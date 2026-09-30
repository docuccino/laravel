<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Docuccino\Laravel\Integrations\Support\JsonApiTopLevel;
use Docuccino\Laravel\Integrations\Support\ResourceWrapping;
use ReflectionClass;

/**
 * Contributes the resource statics a build reads to the environment digest — `$wrap` and `$forceWrapping`
 * ({@see ResourceWrapping}), and the JSON:API `$jsonApiInformation` ({@see JsonApiTopLevel}) — since
 * `JsonResource::withoutWrapping()`, `::wrap()`, a `$forceWrapping` assignment or `JsonApiResource::configure()`
 * in a service provider reshape every resource response while no file records them.
 *
 * Only a value that differs from its declared default is hashed, and only on the class declaring the
 * property: a default is its file's fact and keys through that file, a class never loaded still holds
 * its default, and a subclass sharing its parent's static says nothing the parent does not — so the
 * digest does not move with which resources happen to be loaded.
 */
final class ResourceStaticsDigestContributor implements EnvironmentDigestContributor
{
    private const STATICS = ['wrap', 'forceWrapping', 'jsonApiInformation'];

    public function digest(): string
    {
        // A class not yet loaded holds its declared defaults, so the loaded ones are all there is to read.
        $classes = array_filter(
            get_declared_classes(),
            static fn (string $class): bool => is_a($class, ResourceReflector::JSON_RESOURCE, true),
        );
        sort($classes, SORT_STRING);

        $parts = [];
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            foreach (self::STATICS as $name) {
                array_push($parts, ...self::changed($reflection, $name));
            }
        }

        return implode("\0", $parts);
    }

    /**
     * `[class, property, type, value]` when `$class` declares the static `$name` and holds another value
     * than it declares, else nothing. An anonymous class is skipped: its name carries a NUL and a path.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<string>
     */
    private static function changed(ReflectionClass $class, string $name): array
    {
        if ($class->isAnonymous() || ! $class->hasProperty($name)) {
            return [];
        }

        $property = $class->getProperty($name);
        if (! $property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName()) {
            return [];
        }

        // A typed static declared without a value and never assigned, which reading would throw on.
        if (! $property->isInitialized()) {
            return [];
        }

        $value = $property->getValue();

        if ($property->hasDefaultValue() && $value === $property->getDefaultValue()) {
            return [];
        }

        return [$class->getName(), $name, get_debug_type($value), match (true) {
            is_scalar($value) => (string) $value,
            is_array($value) => hash('sha256', serialize(self::readable($value))),
            default => '',
        }];
    }

    /**
     * `$value` with everything a build could read of it kept exactly — every key, and every scalar with its
     * type, which an encoding that gives up on some values (invalid UTF-8, say) would not — and each object
     * replaced by its type, the most any build reads of one.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function readable(array $value): array
    {
        return array_map(static fn (mixed $item): mixed => match (true) {
            is_array($item) => self::readable($item),
            $item === null || is_scalar($item) => $item,
            default => get_debug_type($item),
        }, $value);
    }
}
