<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Core\Extensions\Context\AttributeSet;
use ReflectionClass;
use ReflectionFunctionAbstract;
use Throwable;

/**
 * Collects the Docuccino attributes declared on a route's action, method-level first then
 * class-level (the {@see AttributeSet} preserves this most-specific-first order, so method
 * attributes beat class attributes — design §7). Only `Docuccino\Attributes\*` instances are
 * materialised; foreign attributes are ignored, and any that fail to instantiate are skipped.
 *
 * @internal
 */
final class AttributeCollector
{
    private const NAMESPACE_PREFIX = 'Docuccino\\Attributes\\';

    public function collect(ReflectedAction $action): AttributeSet
    {
        $set = new AttributeSet;

        $this->addFrom($set, $action->reflection);

        $class = $action->controllerClass;
        if ($class !== null && class_exists($class)) {
            $this->addFrom($set, new ReflectionClass($class));
        }

        return $set;
    }

    /**
     * @param  ReflectionClass<object>|ReflectionFunctionAbstract  $reflection
     */
    private function addFrom(AttributeSet $set, ReflectionClass|ReflectionFunctionAbstract $reflection): void
    {
        foreach ($reflection->getAttributes() as $attribute) {
            if (! str_starts_with($attribute->getName(), self::NAMESPACE_PREFIX)) {
                continue;
            }

            try {
                $set->add($attribute->newInstance());
            } catch (Throwable) {
                // A malformed attribute usage is skipped rather than failing the whole build.
            }
        }
    }
}
