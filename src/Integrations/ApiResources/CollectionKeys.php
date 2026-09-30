<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Laravel\Support\EitherKeyed;
use ReflectionClass;

/**
 * Whether a resource collection keeps the keys of what it holds, and what it is then sent as. Laravel
 * renumbers a collection's integer keys unless it preserves them, and `json_encode` sends preserved keys as
 * an object unless they happen to run 0…n-1 — which the keys a caller holds decide, not the class.
 */
final class CollectionKeys
{
    /** Laravel 13's `#[PreserveKeys]`, beside the `$preserveKeys` property both 12 and 13 read. */
    public const PRESERVE_KEYS_ATTRIBUTE = 'Illuminate\\Http\\Resources\\Attributes\\PreserveKeys';

    /**
     * Whether `$collection` is sent with its keys: its own choice, or for an anonymous collection the
     * collected resource's, which `collection()` copies onto the collection it builds. A caller's
     * `->preserveKeys()` is not traced.
     */
    public static function preserved(ClassT $collection): bool
    {
        if (self::keeps($collection->fqcn)) {
            return true;
        }

        $item = $collection->typeArgs[0] ?? null;

        return ResourceReflector::isAnonymousCollection($collection->fqcn) && $item instanceof ClassT && self::keeps($item->fqcn);
    }

    /**
     * The collection's items as they are sent: a list, or where keys are preserved the array or object
     * the keys make it.
     *
     * @param  array<array-key, mixed>  $items
     * @return array<string, mixed>
     */
    public static function sent(array $items, bool $preserved): array
    {
        return $preserved
            ? EitherKeyed::schema($items)
            : ['type' => 'array', 'items' => $items];
    }

    /**
     * The class's `#[PreserveKeys]` — its own, as PHP attributes are and Laravel reads it, and only where
     * the installed framework ships it — else its `$preserveKeys` default.
     */
    private static function keeps(string $fqcn): bool
    {
        if (! class_exists($fqcn)) {
            return false;
        }

        $class = new ReflectionClass($fqcn);
        if (class_exists(self::PRESERVE_KEYS_ATTRIBUTE) && $class->getAttributes(self::PRESERVE_KEYS_ATTRIBUTE) !== []) {
            return true;
        }

        return ($class->getDefaultProperties()['preserveKeys'] ?? null) === true;
    }
}
