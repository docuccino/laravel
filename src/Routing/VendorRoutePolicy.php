<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

/**
 * Decides the default vendor-route exclusion — the same semantics as Laravel's
 * `route:list --except-vendor`: a route whose resolved controller class file lives under the
 * application's `vendor/` directory is dropped from the docs by default, so an installed package's
 * own routes don't leak into your API reference. Orthogonal to the include/exclude/closure filters
 * (those still apply first, unchanged); this is a strictly-narrowing default that a route escapes
 * only by (a) `routes.include_vendor => true`, or (b) not being a vendor controller — closures and
 * application controllers are never affected.
 *
 * Pure and path-based: the vendor directory is injected (base_path('vendor') in the app, an
 * arbitrary prefix in tests), so the boundary is not hard-coded.
 */
final class VendorRoutePolicy
{
    private readonly string $vendorPrefix;

    public function __construct(string $vendorPath)
    {
        $this->vendorPrefix = rtrim(str_replace('\\', '/', $vendorPath), '/');
    }

    /**
     * Whether the default vendor exclusion drops a route. `$controllerFile` is the controller class's
     * defining file, or null for a closure route / unreflectable action / a controller with no file —
     * all of which are unaffected. `$includeVendor` (the `routes.include_vendor` opt-in) disables the
     * exclusion entirely.
     */
    public function excludesVendorRoute(?string $controllerFile, bool $includeVendor): bool
    {
        if ($includeVendor) {
            return false;
        }

        if ($controllerFile === null || $controllerFile === '') {
            return false; // closure / unreflectable — never a vendor controller
        }

        return $this->isUnderVendor($controllerFile);
    }

    private function isUnderVendor(string $file): bool
    {
        $normalised = rtrim(str_replace('\\', '/', $file), '/');

        return $normalised === $this->vendorPrefix || str_starts_with($normalised, $this->vendorPrefix.'/');
    }
}
