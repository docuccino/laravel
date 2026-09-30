<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\ApiResources\ResourceStaticsDigestContributor;
use ReflectionClass;
use Throwable;

/**
 * Resolves the top-level `data`-wrapping key Laravel applies to a resource response (design §Phase 4
 * — API Resources). Laravel wraps a single resource and an anonymous collection under a key — the
 * resource's static `$wrap` property, default `'data'` — at the response root only; nested resources
 * (a resource inside another's `toArray`) are never wrapped. The statics are read off the booted app,
 * so `JsonResource::withoutWrapping()` in a service provider is seen — and keys the cache through
 * {@see ResourceStaticsDigestContributor}; the per-document `integrations.api_resources.wrap` config
 * overrides both ({@see RepresentationPolicy::$resourceWrap}).
 *
 * Resolution order: config override wins (`disabled` → no wrapping, any other value → forced key),
 * else the resource's own static `$wrap` (default `'data'`, `null` → no wrapping).
 */
final class ResourceWrapping
{
    /**
     * The wrap key for `$fqcn`, or null when the response is not wrapped. `$fqcn` may be null (an
     * anonymous collection with no statically-known item type) — the config override still applies,
     * else the Laravel default `'data'`.
     */
    public static function key(?string $fqcn, RepresentationPolicy $policy): ?string
    {
        if ($policy->resourceWrap === RepresentationPolicy::WRAP_DISABLED) {
            return null;
        }

        if ($policy->resourceWrap !== '') {
            return $policy->resourceWrap;
        }

        return self::staticWrap($fqcn);
    }

    /**
     * Whether `$fqcn` forces its wrap — `$forceWrapping`, which wraps a body that already carries the wrap
     * key. Static, so a parent's value or one set at boot decides it; never where the installed framework
     * declares no such property, since a subclass declaring one there is only a property of its own.
     */
    public static function forced(?string $fqcn): bool
    {
        if ($fqcn === null || ! class_exists($fqcn) || ! property_exists(ResourceReflector::JSON_RESOURCE, 'forceWrapping')) {
            return false;
        }

        try {
            return ((new ReflectionClass($fqcn))->getStaticProperties()['forceWrapping'] ?? null) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** The resource class's static `$wrap` value (default `'data'`; `null` → unwrapped). */
    private static function staticWrap(?string $fqcn): ?string
    {
        if ($fqcn === null || ! class_exists($fqcn)) {
            return 'data';
        }

        try {
            $statics = (new ReflectionClass($fqcn))->getStaticProperties();
        } catch (Throwable) {
            return 'data';
        }

        if (! array_key_exists('wrap', $statics)) {
            return 'data';
        }

        $wrap = $statics['wrap'];

        return is_string($wrap) ? $wrap : null;
    }
}
