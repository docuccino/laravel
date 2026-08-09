<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

use Closure;
use Docuccino\Core\Extensions\Context\DocumentConfig;

/**
 * One row of the {@see IntegrationToggles} table: an integration's identity for the per-document
 * enable/disable gate. Pairs the orthogonal facts the gate needs — whether the package is present
 * ({@see installed()}, unchanged boot-time-agnostic probe) and what the integration contributes when
 * it runs ({@see extensions()}) — with the switch metadata: its config-bag key, a human package label
 * for diagnostics, whether it defaults on when installed, and (for a default-off integration) the
 * opt-in hint the discoverability diagnostic points the user at.
 *
 * @internal
 */
final readonly class IntegrationDescriptor
{
    /**
     * @param  string  $key  the `integrations.<key>` config-bag name (snake_case convention)
     * @param  string  $package  human package/component label used in diagnostics ("spatie/laravel-permission")
     * @param  bool  $defaultEnabled  on when installed unless a document opts out; false only for sensitive-by-activation integrations (permission)
     * @param  string|null  $optInHint  the tail clause of the opt-in diagnostic ("document permission requirements"); null for default-on integrations
     * @param  Closure(?callable(string): bool): bool  $installed  package-presence probe (probe-injectable so the not-installed branch is testable where the package is in fact present); always-on framework built-ins ignore the probe and answer true
     * @param  Closure(): list<class-string|object>  $extensions  the extensions the integration contributes when installed AND enabled
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

    /** Whether this document wants the integration (its `enabled` switch, falling back to the default). */
    public function enabledFor(DocumentConfig $document): bool
    {
        return $document->integrationEnabled($this->key, $this->defaultEnabled);
    }
}
