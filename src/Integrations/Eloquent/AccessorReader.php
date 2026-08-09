<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Inference\CallableRef;
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
 * Discovers a model's accessors — both the classic `getXxxAttribute()` form and the Laravel 9+
 * `xxx(): Attribute { return Attribute::make(get: …); }` form — and, for each, the {@see CallableRef}
 * whose return type IS the accessor's serialised type. {@see ModelSchema} analyses those refs through
 * the engine (real return-type recovery) and lets the result type an appended attribute or override
 * the column/cast it shadows, mirroring `HasAttributes::addMutatedAttributesToArray` (an accessor's
 * value replaces the raw/cast column value, so the cast is skipped).
 *
 * A classic accessor is analysed by name (the method itself is the callable); an `Attribute` accessor
 * is analysed by the LINE of its `get:` closure (the method returns an `Attribute`, not the value), so
 * the file is parsed to locate that closure — the same line-located closure analysis the named
 * rate-limiter uses. Framework-declared methods are skipped, as is any `Attribute` accessor whose get
 * callback is not an inline closure (nothing to locate). Reflection/parse failures yield no accessors,
 * never an error.
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

            $methodNodes = null;
            $accessors = [];
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')) {
                    continue;
                }

                $name = $method->getName();

                // Classic accessor: `getFullNameAttribute()` types the `full_name` attribute; analysed
                // by name (the method's own return type is the attribute type).
                if (preg_match('/^get(.+)Attribute$/', $name, $matches) === 1) {
                    $accessors[] = [
                        'attribute' => Str::snake($matches[1]),
                        'ref' => new CallableRef($file, $fqcn, $name),
                    ];

                    continue;
                }

                // Attribute accessor: `fullName(): Attribute` types the `full_name` attribute via its
                // `get:` closure — located by line so the engine analyses the closure, not the method.
                if (! self::returnsAttribute($method)) {
                    continue;
                }

                $methodNodes ??= ParsedClassFile::methods($file);
                $line = $this->getClosureLine($methodNodes[$name] ?? null);
                if ($line !== null) {
                    $accessors[] = [
                        'attribute' => Str::snake($name),
                        'ref' => new CallableRef($file, null, null, $line),
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
     * The start line of the `get:` closure inside an `Attribute::make(...)` / `Attribute::get(...)` /
     * `new Attribute(...)` call within the method, or null when there is no inline closure to locate.
     */
    private function getClosureLine(?ClassMethod $method): ?int
    {
        if ($method === null) {
            return null;
        }

        foreach ((new NodeFinder)->find($method->stmts ?? [], static fn (object $n): bool => self::isAttributeCall($n)) as $call) {
            /** @var StaticCall|New_ $call */
            $getArg = self::getCallbackArg($call);
            if ($getArg instanceof Closure || $getArg instanceof ArrowFunction) {
                return $getArg->getStartLine();
            }
        }

        return null;
    }

    /** Whether a node is an `Attribute::make(...)`/`Attribute::get(...)` static call or `new Attribute(...)`. */
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
     * The get-callback argument of an Attribute call: the `get:` named argument, else the first
     * positional argument (`Attribute::make(get, set)` / `Attribute::get(get)`).
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
