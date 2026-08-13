<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Passport\PassportIntegration;
use Docuccino\Laravel\Integrations\Sanctum\SanctumDetector;
use Docuccino\Laravel\Integrations\Sanctum\SanctumIntegration;

/**
 * The Sanctum/Passport integrations gate on, and match against, hard-coded framework FQCN strings
 * (registrar `class_exists` guards, the stateful-middleware the detector looks for). A silent typo
 * in any of them would make an integration never register or never detect — invisible to the golden
 * suite (the feature simply never fires). These assert every such string resolves against the
 * installed packages (sanctum + passport are dev requirements), closing that typo risk (arch B8).
 */
it('resolves every registrar guard + matcher FQCN string against the installed packages', function (string $fqcn): void {
    expect(class_exists($fqcn) || interface_exists($fqcn))->toBeTrue("FQCN string does not resolve: {$fqcn}");
})->with([
    'Sanctum registrar guard' => [SanctumIntegration::SANCTUM],
    'Passport registrar guard' => [PassportIntegration::PASSPORT],
    'Sanctum stateful middleware matcher' => [
        (new ReflectionClass(SanctumDetector::class))->getReflectionConstant('STATEFUL_MIDDLEWARE')->getValue(),
    ],
    // The ::using() FQCN middleware forms the parsers/detector now match must resolve too — a typo
    // in any would make that form silently unrecognised (invisible to the golden suite).
    'Sanctum CheckAbilities matcher' => ['Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities'],
    'Sanctum CheckForAnyAbility matcher' => ['Laravel\\Sanctum\\Http\\Middleware\\CheckForAnyAbility'],
    'Sanctum legacy CheckScopes matcher' => ['Laravel\\Sanctum\\Http\\Middleware\\CheckScopes'],
    'Sanctum legacy CheckForAnyScope matcher' => ['Laravel\\Sanctum\\Http\\Middleware\\CheckForAnyScope'],
    'Permission RoleMiddleware matcher' => ['Spatie\\Permission\\Middleware\\RoleMiddleware'],
    'Permission PermissionMiddleware matcher' => ['Spatie\\Permission\\Middleware\\PermissionMiddleware'],
    'Permission RoleOrPermissionMiddleware matcher' => ['Spatie\\Permission\\Middleware\\RoleOrPermissionMiddleware'],
]);

/**
 * Passport's scope middleware were all renamed in Passport 13, so which spellings must resolve depends
 * on the installed major. Asserting only "one of the spellings resolves" would let a typo in the other
 * generation through, so the expected set is chosen from the install and asserted in full — the CI
 * Laravel 12 and Laravel 13 legs then cover one generation each.
 */
it('resolves every Passport scope-middleware FQCN for the installed Passport major', function (string $fqcn): void {
    expect(class_exists($fqcn))->toBeTrue("FQCN string does not resolve: {$fqcn}");
})->with(
    class_exists('Laravel\\Passport\\Http\\Middleware\\CheckToken')
        ? [
            'CheckToken (all-of)' => ['Laravel\\Passport\\Http\\Middleware\\CheckToken'],
            'CheckTokenForAnyScope (any-of)' => ['Laravel\\Passport\\Http\\Middleware\\CheckTokenForAnyScope'],
            'EnsureClientIsResourceOwner (client credentials)' => ['Laravel\\Passport\\Http\\Middleware\\EnsureClientIsResourceOwner'],
        ]
        : [
            'CheckScopes (all-of)' => ['Laravel\\Passport\\Http\\Middleware\\CheckScopes'],
            'CheckForAnyScope (any-of)' => ['Laravel\\Passport\\Http\\Middleware\\CheckForAnyScope'],
            'CheckClientCredentials (client credentials)' => ['Laravel\\Passport\\Http\\Middleware\\CheckClientCredentials'],
            'CheckClientCredentialsForAnyScope (client credentials, any-of)' => ['Laravel\\Passport\\Http\\Middleware\\CheckClientCredentialsForAnyScope'],
        ]
);
