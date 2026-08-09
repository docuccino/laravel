<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Passport;

/**
 * The scopes a Passport-protected route demands, split by the semantics that produced them:
 * `scopes:`/`CheckScopes` require all of {@see $allOf}, `scope:`/`CheckForAnyScope` any one of
 * {@see $anyOf}. OAS spells all-of as one requirement's scope list and any-of as an OR-list of
 * requirements, so they must stay apart until the requirement is built.
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
     * One entry when there are no any-of scopes, else one per any-of scope, each combined with the
     * always-required all-of scopes.
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
