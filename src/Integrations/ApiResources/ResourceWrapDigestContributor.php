<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Docuccino\Laravel\Integrations\Support\ResourceWrapping;
use ReflectionClass;

/**
 * Contributes the resource wrap statics {@see ResourceWrapping} reads — `$wrap` and `$forceWrapping` — to
 * the environment digest, since `JsonResource::withoutWrapping()`, `::wrap()` or a `$forceWrapping`
 * assignment in a service provider reshape every resource response while no file records them.
 *
 * Only a value that differs from its declared default is hashed, and only on the class declaring the
 * property: a default is its file's fact and keys through that file, a class never loaded still holds
 * its default, and a subclass sharing its parent's static says nothing the parent does not — so the
 * digest does not move with which resources happen to be loaded.
 */
final class ResourceWrapDigestContributor implements EnvironmentDigestContributor
{
    private const STATICS = ['wrap', 'forceWrapping'];

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

        return [$class->getName(), $name, get_debug_type($value), is_scalar($value) ? (string) $value : ''];
    }
}
