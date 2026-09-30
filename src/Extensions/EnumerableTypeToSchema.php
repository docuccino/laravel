<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Support\EitherKeyed;
use Docuccino\Laravel\Support\FrameworkClasses;
use ReflectionClass;
use ReflectionMethod;

/**
 * A Laravel collection as the array it holds, keys included: a list or an object of its items, whatever its
 * key type, since no key type proves which (docs/design/uir-and-extensions.md §8). Items are claimed only
 * where the collection is sent by the framework's own methods — read off the class an Eloquent collection is
 * actually built as, which its model configures and Larastan does not name. `EARLY` so core's class mapper
 * never reflects one into an empty component.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class EnumerableTypeToSchema implements TypeToSchema
{
    /** What a collection is sent by. A class overriding any of them sends what the override returns. */
    private const SENT_BY = ['all', 'jsonSerialize', 'toJson', 'getIterator'];

    private const ELOQUENT_COLLECTION = 'Illuminate\\Database\\Eloquent\\Collection';

    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    private const COLLECTED_BY = 'Illuminate\\Database\\Eloquent\\Attributes\\CollectedBy';

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT && FrameworkClasses::isCollection($type->fqcn);
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT || ! $this->supports($type)) {
            return null;
        }

        if (! self::sendsItsItems($type)) {
            return new SchemaResult([], 0.4);
        }

        // `<TKey, TValue>` on every framework collection; anything else says nothing about the items.
        $value = count($type->typeArgs) === 2 ? $type->typeArgs[1] : null;

        return new SchemaResult(EitherKeyed::schema($value !== null ? $context->convert($value) : []));
    }

    /**
     * Whether the collection is sent as the array it holds. An Eloquent collection is built as the class its
     * items' model names — `$collectionClass` or `#[CollectedBy]` anywhere in its hierarchy, traits
     * included, or whatever an overriding `newCollection()` builds — so that class is the one read, and one
     * that cannot be read is no answer.
     */
    private static function sendsItsItems(ClassT $type): bool
    {
        if (! self::sentByTheFramework($type->fqcn)) {
            return false;
        }
        if (! is_a($type->fqcn, self::ELOQUENT_COLLECTION, true)) {
            return true;
        }

        $model = $type->typeArgs[1] ?? null;
        $configured = $model instanceof ClassT ? self::configuredCollections($model->fqcn) : null;
        if ($configured === null) {
            return false;
        }

        foreach ($configured as $collection) {
            if (! is_a($collection, self::ELOQUENT_COLLECTION, true) || ! self::sentByTheFramework($collection)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every collection class `$model` may build its collections as, or null where its `newCollection()` is
     * its own or an attribute names no readable class.
     *
     * @return list<string>|null
     */
    private static function configuredCollections(string $model): ?array
    {
        if (! is_subclass_of($model, self::MODEL) || ! class_exists($model)) {
            return null;
        }

        $reflection = new ReflectionClass($model);
        if ($reflection->isAbstract()) {
            // Its collections are its subclasses', each configured on its own.
            return null;
        }
        foreach (['newCollection', 'resolveCollectionFromAttribute'] as $method) {
            if (method_exists($model, $method) && ! self::frameworks((new ReflectionMethod($model, $method))->getDeclaringClass())) {
                return null;
            }
        }

        $configured = [];
        $default = $reflection->getDefaultProperties()['collectionClass'] ?? null;
        if (is_string($default)) {
            $configured[] = $default;
        }
        for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
            foreach ([$class, ...array_values($class->getTraits())] as $declaring) {
                foreach ($declaring->getAttributes(self::COLLECTED_BY) as $attribute) {
                    $named = $attribute->getArguments()[0] ?? null;
                    if (! is_string($named)) {
                        return null;
                    }
                    $configured[] = $named;
                }
            }
        }

        return $configured;
    }

    private static function sentByTheFramework(string $fqcn): bool
    {
        foreach (self::SENT_BY as $method) {
            if (method_exists($fqcn, $method) && ! self::frameworks((new ReflectionMethod($fqcn, $method))->getDeclaringClass())) {
                return false;
            }
        }

        return true;
    }

    /**
     * An anonymous class is named for what it extends, so it is never the framework's.
     *
     * @param  ReflectionClass<object>  $class
     */
    private static function frameworks(ReflectionClass $class): bool
    {
        return str_starts_with($class->getName(), 'Illuminate\\') && ! $class->isAnonymous();
    }
}
