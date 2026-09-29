<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * The relation-method calls a model's own methods make — every `$this->belongsTo(...)`, say — read
 * statically: reflection finds the candidates, each declaring file is parsed once, and each body read is
 * the one PHP runs for the method, never a sibling's of that name. What a call means is left to the reader
 * asking ({@see BelongsToReader}, {@see MorphToReader}).
 */
final class RelationCalls
{
    /**
     * Every candidate relation method — public, non-static, callable with no arguments, not the
     * framework's — whose body calls `$this->{$relation}()`, with those calls. Grouped by declaring file,
     * then in reflection order.
     *
     * @return list<array{method: ReflectionMethod, calls: non-empty-list<MethodCall>}>
     */
    public static function of(string $model, string $relation): array
    {
        if (! class_exists($model)) {
            return [];
        }

        try {
            $methods = (new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC);
        } catch (Throwable) {
            return [];
        }

        $byFile = [];
        foreach ($methods as $method) {
            if ($method->isStatic()
                || $method->getNumberOfRequiredParameters() !== 0
                || str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')
            ) {
                continue;
            }

            $file = $method->getFileName();
            if ($file !== false) {
                $byFile[$file][] = $method;
            }
        }

        $found = [];
        foreach ($byFile as $file => $candidates) {
            $statements = ParsedClassFile::statements($file);
            foreach ($candidates as $method) {
                $node = ParsedClassFile::declarationOf($method, $statements);
                if ($node === null) {
                    continue;
                }

                $calls = array_values(array_filter(
                    (new NodeFinder)->findInstanceOf($node->stmts ?? [], MethodCall::class),
                    static fn (MethodCall $call): bool => $call->var instanceof Node\Expr\Variable
                        && $call->var->name === 'this'
                        && $call->name instanceof Node\Identifier
                        && $call->name->toString() === $relation
                        && ! $call->isFirstClassCallable(),
                ));
                if ($calls !== []) {
                    $found[] = ['method' => $method, 'calls' => $calls];
                }
            }
        }

        return $found;
    }

    /**
     * The call's argument expressions keyed by parameter name, positional and named both, or null where
     * they can't all be placed: an unpack, a name the signature doesn't have, a parameter given twice.
     *
     * @param  list<string>  $parameters  the relation method's parameters, in signature order
     * @return array<string, Node\Expr>|null
     */
    public static function arguments(MethodCall $call, array $parameters): ?array
    {
        $arguments = [];
        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->unpack) {
                return null;
            }

            $name = $arg->name?->toString() ?? ($parameters[$index] ?? null);
            if ($name === null || ! in_array($name, $parameters, true) || array_key_exists($name, $arguments)) {
                return null;
            }

            $arguments[$name] = $arg->value;
        }

        return $arguments;
    }
}
