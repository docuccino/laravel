<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

use Closure;
use Docuccino\Core\Extensions\Context\DocumentConfig;

/**
 * One row of the {@see IntegrationToggles} table: the two orthogonal facts the gate needs — is the
 * package present, and what does the integration contribute — plus the switch metadata (config key,
 * package label for diagnostics, default-on/off, and the opt-in hint for a default-off one).
 *
 * @internal
 */
final readonly class IntegrationDescriptor
{
    /**
     * @param  string  $key  the `integrations.<key>` config-bag name (snake_case)
     * @param  string  $package  package label for diagnostics, e.g. "spatie/laravel-permission"
     * @param  bool  $defaultEnabled  on when installed, unless a document opts out; false only for sensitive-by-activation integrations
     * @param  string|null  $optInHint  tail clause of the opt-in diagnostic; null for default-on integrations
     * @param  Closure(?callable(string): bool): bool  $installed  package-presence probe, injectable so the not-installed branch stays testable when the package IS present; framework built-ins ignore it and answer true
     * @param  Closure(): list<class-string|object>  $extensions  contributed when installed AND enabled
     */
    public function __construct(
        public string $key,
        public string $package,
        public bool $defaultEnabled,
        public ?string $optInHint,
        private Closure $installed,
        private Closure $extensions,
    ) {}

    /**
     * @param  (callable(string): bool)|null  $probe
     */
    public function installed(?callable $probe = null): bool
    {
        return ($this->installed)($probe);
    }

    /**
     * @return list<class-string|object>
     */
    public function extensions(): array
    {
        return ($this->extensions)();
    }

    /** This document's `enabled` switch, falling back to the default. */
    public function enabledFor(DocumentConfig $document): bool
    {
        return $document->integrationEnabled($this->key, $this->defaultEnabled);
    }
}
