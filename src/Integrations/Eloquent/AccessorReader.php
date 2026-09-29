<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\DeclarationLine;
use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use Illuminate\Support\Str;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Discovers a model's accessors — the classic `getXxxAttribute()` form and the Laravel 9+
 * `xxx(): Attribute { return Attribute::make(get: …); }` form — and hands back a {@see CallableRef} whose
 * return type IS the serialised attribute type. {@see ModelSchema} analyses those and lets the result
 * override the column/cast it shadows, mirroring `HasAttributes::addMutatedAttributesToArray`.
 *
 * A classic accessor is referenced by name. An `Attribute` accessor can't be — the method returns an
 * `Attribute`, not the value — so the file is parsed to find the `get:` closure and the ref points at its
 * LINE instead. Framework-declared methods are skipped, as is any `get:` that isn't an inline closure.
 * Reflection or parse failures yield no accessors rather than an error.
 */
final class AccessorReader
{
    private const ATTRIBUTE = 'Illuminate\\Database\\Eloquent\\Casts\\Attribute';

    private const ATTRIBUTE_SHORT = 'Attribute';

    /**
     * @return list<array{attribute: string, ref: CallableRef}>
     */
    public function read(string $fqcn): array
    {
        if (! class_exists($fqcn)) {
            return [];
        }

        try {
            $reflection = new ReflectionClass($fqcn);
            $file = $reflection->getFileName();
            if ($file === false) {
                return [];
            }

            $statements = [];
            $sources = [];
            $accessors = [];
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')) {
                    continue;
                }

                $name = $method->getName();

                // `getFullNameAttribute()` types the `full_name` attribute.
                if (preg_match('/^get(.+)Attribute$/', $name, $matches) === 1) {
                    $accessors[] = [
                        'attribute' => Str::snake($matches[1]),
                        'ref' => new CallableRef($file, $fqcn, $name),
                    ];

                    continue;
                }

                // `fullName(): Attribute` types `full_name` via its `get:` closure, located by line.
                if (! self::returnsAttribute($method)) {
                    continue;
                }

                // The body PHP runs is where its closure is, and a parent or a trait may write it.
                $written = $method->getFileName();
                if ($written === false) {
                    continue;
                }
                $statements[$written] ??= ParsedClassFile::statements($written);
                $sources[$written] ??= (string) file_get_contents($written);
                $line = $this->getClosureLine(ParsedClassFile::declarationOf($method, $statements[$written]), $sources[$written]);
                if ($line !== null) {
                    $accessors[] = [
                        'attribute' => Str::snake($name),
                        'ref' => new CallableRef($written, null, null, $line),
                    ];
                }
            }

            return $accessors;
        } catch (Throwable) {
            return [];
        }
    }

    private static function returnsAttribute(ReflectionMethod $method): bool
    {
        $type = $method->getReturnType();

        return $type instanceof ReflectionNamedType && $type->getName() === self::ATTRIBUTE;
    }

    /**
     * The line reflection gives the `get:` closure inside the method's Attribute call, if there is one —
     * the line the engine looks a closure up by, which is its keyword's and not its first modifier's.
     */
    private function getClosureLine(?ClassMethod $method, string $source): ?int
    {
        if ($method === null) {
            return null;
        }

        foreach ((new NodeFinder)->find($method->stmts ?? [], static fn (object $n): bool => self::isAttributeCall($n)) as $call) {
            /** @var StaticCall|New_ $call */
            $getArg = self::getCallbackArg($call);
            if ($getArg instanceof Closure || $getArg instanceof ArrowFunction) {
                return DeclarationLine::of($getArg, $source);
            }
        }

        return null;
    }

    /** An `Attribute::make(...)`/`Attribute::get(...)` call, or `new Attribute(...)`. */
    private static function isAttributeCall(object $node): bool
    {
        if ($node instanceof StaticCall
            && $node->class instanceof Name
            && self::namesAttribute($node->class)
            && $node->name instanceof Identifier
            && in_array($node->name->toString(), ['make', 'get'], true)
        ) {
            return true;
        }

        return $node instanceof New_ && $node->class instanceof Name && self::namesAttribute($node->class);
    }

    private static function namesAttribute(Name $name): bool
    {
        return $name->toString() === self::ATTRIBUTE || $name->getLast() === self::ATTRIBUTE_SHORT;
    }

    /**
     * The `get:` named argument, else the first positional one — `get` comes first in both
     * `Attribute::make(get, set)` and `Attribute::get(get)`.
     */
    private static function getCallbackArg(StaticCall|New_ $call): ?object
    {
        $positional = null;
        foreach ($call->args as $index => $arg) {
            if (! $arg instanceof Arg) {
                continue;
            }
            if ($arg->name instanceof Identifier && $arg->name->toString() === 'get') {
                return $arg->value;
            }
            if ($index === 0 && $arg->name === null) {
                $positional = $arg->value;
            }
        }

        return $positional;
    }
}
