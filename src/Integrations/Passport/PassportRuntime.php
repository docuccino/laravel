<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

/**
 * The Passport runtime facts the oauth2 scheme needs, read once from the installed package by the
 * service-provider wiring (which is allowed to touch the vendor class) and injected here so the
 * integration itself stays vendor-import-free per the dogfooding arch rule: the app's scope catalogue
 * (`Passport::tokensCan()`), and whether the deprecated password / implicit grants were opted into.
 */
final readonly class PassportRuntime
{
    /**
     * @param  array<string, string>  $scopes  Scope id → description.
     */
    public function __construct(
        public array $scopes = [],
        public bool $passwordGrant = false,
        public bool $implicitGrant = false,
    ) {}
}
