<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

/**
 * The default vendor-route exclusion, matching `route:list --except-vendor`: a route whose controller
 * file lives under `vendor/` is dropped, so an installed package's routes don't leak into your API
 * reference. Strictly narrowing and applied after the include/exclude/closure filters; a route escapes
 * only via `routes.include_vendor => true` or by not being a vendor controller. Closures and app
 * controllers are never touched.
 *
 * Pure and path-based — the vendor directory is injected, not hard-coded.
 */
final class VendorRoutePolicy
{
    private readonly string $vendorPrefix;

    public function __construct(string $vendorPath)
    {
        $this->vendorPrefix = rtrim(str_replace('\\', '/', $vendorPath), '/');
    }

    /**
     * `$controllerFile` is the controller's defining file, or null for a closure / unreflectable action
     * / a fileless controller — none of which are excluded. `$includeVendor` disables the rule outright.
     */
    public function excludesVendorRoute(?string $controllerFile, bool $includeVendor): bool
    {
        if ($includeVendor) {
            return false;
        }

        return $this->isVendorFile($controllerFile);
    }

    /**
     * Whether a file lives inside the application's vendor directory — the boundary itself, without the
     * route-exclusion question wrapped around it. Nothing (a closure, an unreflectable action) is vendor.
     */
    public function isVendorFile(?string $file): bool
    {
        return $file !== null && $file !== '' && $this->isUnderVendor($file);
    }

    private function isUnderVendor(string $file): bool
    {
        $normalised = rtrim(str_replace('\\', '/', $file), '/');

        return $normalised === $this->vendorPrefix || str_starts_with($normalised, $this->vendorPrefix.'/');
    }
}
