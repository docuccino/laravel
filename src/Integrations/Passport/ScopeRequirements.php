<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

/**
 * The scopes a Passport-protected route demands, split by the middleware semantics that produced
 * them: `scopes:`/`CheckScopes` require ALL of {@see $allOf}; `scope:`/`CheckForAnyScope` require ANY
 * ONE of {@see $anyOf}. OAS expresses all-of as a single requirement's scope list and any-of as an
 * OR-list of requirements, so the two must be kept apart until the security requirement is built.
 */
final readonly class ScopeRequirements
{
    /**
     * @param  list<string>  $allOf
     * @param  list<string>  $anyOf
     */
    public function __construct(
        public array $allOf = [],
        public array $anyOf = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->allOf === [] && $this->anyOf === [];
    }

    /**
     * Every distinct scope referenced, for building the scheme's flow scope map.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return array_values(array_unique([...$this->allOf, ...$this->anyOf]));
    }

    /**
     * The OAS security requirement scope-lists this demands against the given scheme name: one entry
     * for all-of (or when no any-of scopes exist), else one entry per any-of scope (each combined
     * with the always-required all-of scopes).
     *
     * @return list<array<string, list<string>>>
     */
    public function toSecurity(string $scheme): array
    {
        if ($this->anyOf === []) {
            return [[$scheme => $this->allOf]];
        }

        $requirements = [];
        foreach ($this->anyOf as $scope) {
            $requirements[] = [$scheme => array_values(array_unique([...$this->allOf, $scope]))];
        }

        return $requirements;
    }
}
