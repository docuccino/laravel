<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Extensions\Schema\DeclaredReturnType;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\UnionT;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use ReflectionMethod;

/**
 * Reads a model's `morphTo` relations statically, as {@see BelongsToReader} reads `belongsTo`: which
 * column holds each relation's morph type, and which models the relation's declared generic says it
 * holds (`MorphTo<Post|Video, $this>`). A call whose type column can't be read surfaces as a refusal,
 * so no answer is published for a column it might own.
 *
 * @phpstan-type MorphToRelations array{readable: array<string, list<class-string>|null>, refused: list<string|null>}
 */
final class MorphToReader
{
    /** Laravel's `morphTo()` parameters, in signature order — positional args map onto these names. */
    private const PARAMETERS = ['name', 'type', 'id', 'ownerKey'];

    /** @var array<string, MorphToRelations> */
    private array $memo = [];

    /**
     * `readable` maps each morph type column to the models its relations declare, null where a relation
     * declares none it can be held to (`MorphTo<Model, $this>`, no generic). `refused` holds the type
     * column of each call read only in part, null where even that was unreadable.
     *
     * @return MorphToRelations
     */
    public function relations(string $model): array
    {
        return $this->memo[$model] ??= $this->read($model);
    }

    /**
     * @return MorphToRelations
     */
    private function read(string $model): array
    {
        $relations = ['readable' => [], 'refused' => []];
        foreach (RelationCalls::of($model, 'morphTo') as ['method' => $method, 'calls' => $calls]) {
            // Several calls in one body (a conditional relation) leave which one runs to runtime.
            $column = count($calls) === 1 ? self::column($method->getName(), $calls[0]) : false;
            if ($column === false) {
                foreach ($calls as $call) {
                    $relations['refused'][] = self::refusedColumn($method->getName(), $call);
                }

                continue;
            }

            $targets = self::targets($method);
            $relations['readable'][$column] = array_key_exists($column, $relations['readable'])
                ? self::merge($relations['readable'][$column], $targets)
                : $targets;
        }

        return $relations;
    }

    /**
     * The column the call's morph type lives in — `$type`, else `snake($name).'_type'`, the name
     * defaulting to the relation method's own, exactly as `morphTo()` and `getMorphs()` resolve them.
     * False when an argument that decides it isn't a literal. The id and owner key decide nothing about
     * the type column, so they may be anything.
     */
    private static function column(string $method, MethodCall $call): string|false
    {
        $arguments = RelationCalls::arguments($call, self::PARAMETERS);
        if ($arguments === null) {
            return false;
        }

        $type = isset($arguments['type']) ? self::literal($arguments['type'], $method) : null;
        $name = isset($arguments['name']) ? self::literal($arguments['name'], $method) : null;
        if ($type === false || $name === false) {
            return false;
        }

        // `?:` in the framework, so an empty string falls back like an absent one.
        if ($type !== null && $type !== '') {
            return $type;
        }

        return Str::snake($name !== null && $name !== '' ? $name : $method).'_type';
    }

    /** The column a partly-readable call still names, or null where it names none that can be read. */
    private static function refusedColumn(string $method, MethodCall $call): ?string
    {
        $column = self::column($method, $call);

        return $column === false ? null : $column;
    }

    /**
     * A string literal, `__FUNCTION__` (Laravel's documented spelling of "this relation's name"), or an
     * explicit `null`; anything else is false.
     */
    private static function literal(Node\Expr $expr, string $method): string|null|false
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_ => $expr->value,
            $expr instanceof Node\Scalar\MagicConst\Function_ => $method,
            $expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'null' => null,
            default => false,
        };
    }

    /**
     * The models the relation's `@return` generic names, or null unless every member of it is a model:
     * `MorphTo<Model, $this>` is the framework's own "any model", and says nothing to narrow by.
     *
     * @return list<class-string>|null
     */
    private static function targets(ReflectionMethod $method): ?array
    {
        $declared = DeclaredReturnType::of($method);
        if (! $declared instanceof ClassT || ! is_a($declared->fqcn, 'Illuminate\\Database\\Eloquent\\Relations\\MorphTo', true)) {
            return null;
        }

        $related = $declared->typeArgs[0] ?? null;
        $members = $related instanceof UnionT ? $related->members : [$related];

        $targets = [];
        foreach ($members as $member) {
            if (! $member instanceof ClassT || ! EloquentModelReflector::isModel($member->fqcn)) {
                return null;
            }
            /** @var class-string $fqcn */
            $fqcn = $member->fqcn;
            $targets[] = $fqcn;
        }

        return $targets === [] ? null : $targets;
    }

    /**
     * Two relations on one column: the column holds what either may write, and nothing is known where
     * either is unknown.
     *
     * @param  list<class-string>|null  $a
     * @param  list<class-string>|null  $b
     * @return list<class-string>|null
     */
    private static function merge(?array $a, ?array $b): ?array
    {
        return $a === null || $b === null ? null : array_values(array_unique([...$a, ...$b]));
    }
}
