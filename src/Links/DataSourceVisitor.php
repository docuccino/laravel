<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Links;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use PhpParser\Node;
use Traversable;

/**
 * What the walk sees each Data object built from, typed by the analyser: `new XData(id: $post->id)`,
 * `XData::from($post)`, `XData::from(['id' => $post->id])` and `XData::collect($posts)`. A value is a
 * model's column only where it is written `$model->column` (or `$model->getKey()`, `->getRouteKey()`) on
 * exactly one model class; {@see DataColumns} decides what laravel-data does with it.
 *
 * Anything else a Data class meets is a construction that names nothing — another static call on it (a
 * custom `from*()`, a factory), an argument unpacked, an instance method called on something that may be
 * one of its objects (`withoutWrapping()`, `additional()`, through its own type or one it extends) — and so
 * stands against every property it might have set.
 *
 * @internal
 */
final class DataSourceVisitor implements TraceVisitor
{
    /** @var array<string, list<DataConstruction>> Data class → what each construction of it was built from */
    private array $built = [];

    /** @var array<string, true> each class an instance method was called on, the receiver typed as it */
    private array $called = [];

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name && DataClassReflector::isData($node->class->toString())) {
            $this->built[$node->class->toString()][] = self::arguments(array_values($node->getArgs()), $scope);
        } elseif ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && DataClassReflector::isData($node->class->toString())) {
            $this->built[$node->class->toString()][] = self::staticCall($node, $scope);
        } elseif ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            foreach (self::classes($scope->typeOf($node->var)) as $receiver) {
                $this->called[$receiver] = true;
            }
        }

        // Descend into any app-code call, so a Data object a model method builds is seen too.
        return $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall;
    }

    /**
     * What each construction of `$data` the walk saw was built from, or null where it saw none.
     *
     * @return list<DataConstruction>|null
     */
    public function constructionsOf(string $data): ?array
    {
        $built = $this->built[$data] ?? null;
        if ($built === null) {
            return null;
        }

        // A receiver typed as the class, or as any class or interface it extends (a helper's `Data $data`, a
        // template bound to one), may be one of its objects, and the call may change what it sends.
        foreach (array_keys($this->called) as $receiver) {
            if (is_a($data, $receiver, true)) {
                return [...$built, DataConstruction::unknown()];
            }
        }

        return $built;
    }

    private static function staticCall(Node\Expr\StaticCall $call, TypeScope $scope): DataConstruction
    {
        $name = $call->name instanceof Node\Identifier ? $call->name->toString() : null;
        $args = $call->getArgs();
        $first = $args[0] ?? null;

        if ($first === null || $first->unpack || $first->name !== null) {
            return DataConstruction::unknown();
        }

        if ($name === 'from' && count($args) === 1) {
            if ($first->value instanceof Node\Expr\Array_) {
                return self::array($first->value, $scope);
            }

            $model = self::model($scope->typeOf($first->value));

            return $model === null ? DataConstruction::unknown() : DataConstruction::model($model);
        }

        if ($name === 'collect') {
            $model = self::itemModel($scope->typeOf($first->value));

            return $model === null ? DataConstruction::unknown() : DataConstruction::model($model);
        }

        return DataConstruction::unknown();
    }

    /**
     * @param  list<Node\Arg>  $args
     */
    private static function arguments(array $args, TypeScope $scope): DataConstruction
    {
        $sources = [];

        foreach ($args as $position => $arg) {
            if ($arg->unpack) {
                return DataConstruction::unknown();
            }

            $sources[$arg->name === null ? $position : $arg->name->toString()] = self::source($arg->value, $scope);
        }

        return DataConstruction::arguments($sources);
    }

    private static function array(Node\Expr\Array_ $array, TypeScope $scope): DataConstruction
    {
        $sources = [];

        foreach ($array->items as $item) {
            if ($item->unpack || ! $item->key instanceof Node\Scalar\String_) {
                return DataConstruction::unknown();
            }

            $sources[$item->key->value] = self::source($item->value, $scope);
        }

        return DataConstruction::payload($sources);
    }

    /**
     * The model column `$value` reads, or a key marker for `getKey()`/`getRouteKey()`.
     *
     * @return array{0: string, 1: string}|null [model, column or marker]
     */
    private static function source(Node\Expr $value, TypeScope $scope): ?array
    {
        if ($value instanceof Node\Expr\PropertyFetch && $value->name instanceof Node\Identifier) {
            $model = self::model($scope->typeOf($value->var));

            return $model === null ? null : [$model, $value->name->toString()];
        }

        if ($value instanceof Node\Expr\MethodCall && $value->name instanceof Node\Identifier && $value->getArgs() === []) {
            $marker = match ($value->name->toString()) {
                'getKey' => BodyColumns::PRIMARY_KEY,
                'getRouteKey' => BodyColumns::ROUTE_KEY,
                default => null,
            };
            if ($marker === null) {
                return null;
            }

            $model = self::model($scope->typeOf($value->var));

            return $model === null ? null : [$model, $marker];
        }

        return null;
    }

    /**
     * Every class `$type` may be an object of.
     *
     * @return list<string>
     */
    private static function classes(DType $type): array
    {
        if ($type instanceof ClassT) {
            return [$type->fqcn];
        }

        if ($type instanceof UnionT || $type instanceof IntersectionT) {
            return array_merge(...array_map(self::classes(...), $type->members));
        }

        return [];
    }

    /** The one model class `$type` is, or null. */
    private static function model(DType $type): ?string
    {
        return $type instanceof ClassT && EloquentModelReflector::isModel($type->fqcn) ? $type->fqcn : null;
    }

    /** The one model class every item of an iterable is, or null. */
    private static function itemModel(DType $type): ?string
    {
        if ($type instanceof ListT || $type instanceof MapT) {
            return self::model($type->value);
        }

        if ($type instanceof ClassT && is_a($type->fqcn, Traversable::class, true)) {
            $item = $type->typeArgs === [] ? null : $type->typeArgs[array_key_last($type->typeArgs)];

            return $item === null ? null : self::model($item);
        }

        return null;
    }
}
