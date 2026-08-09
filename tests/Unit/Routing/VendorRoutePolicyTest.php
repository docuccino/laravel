<?php

declare(strict_types=1);

use Docuccino\Laravel\Routing\VendorRoutePolicy;

/**
 * The pure default-vendor-exclusion decision (route:list --except-vendor semantics): a vendor
 * controller file is dropped by default, the include_vendor opt-in keeps it, and application
 * controllers + closures (null file) are never affected.
 */
it('decides the default vendor exclusion per input', function (?string $controllerFile, bool $includeVendor, bool $excluded): void {
    $policy = new VendorRoutePolicy('/srv/app/vendor');

    expect($policy->excludesVendorRoute($controllerFile, $includeVendor))->toBe($excluded);
})->with([
    'vendor controller excluded by default' => ['/srv/app/vendor/acme/pkg/src/PkgController.php', false, true],
    'include_vendor opt-in keeps it' => ['/srv/app/vendor/acme/pkg/src/PkgController.php', true, false],
    'application controller unaffected' => ['/srv/app/app/Http/Controllers/UserController.php', false, false],
    'closure route (null file) unaffected' => [null, false, false],
]);
