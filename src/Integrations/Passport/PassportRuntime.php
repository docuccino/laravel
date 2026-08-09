<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

/**
 * The Passport facts the oauth2 scheme needs — the `Passport::tokensCan()` catalogue and whether the
 * deprecated password/implicit grants were opted into. Read once by the service-provider wiring, which is
 * allowed to touch the vendor class, and injected here so the integration itself stays vendor-import-free.
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
