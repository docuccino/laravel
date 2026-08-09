<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

/**
 * One Sanctum token-ability requirement recovered from an `abilities:`/`ability:` middleware (or the
 * legacy scope middleware, or a `#[Abilities]` attribute): its `match` semantics — `all` for
 * `CheckAbilities`/`abilities:`, `any` for `CheckForAnyAbility`/`ability:` — and the abilities it
 * demands. Feeds both the `x-abilities` extension member and the generated description line. Because
 * `sanctumToken` is an HTTP bearer scheme, OAS cannot carry abilities as scopes, so they live here.
 */
final readonly class AbilityRequirement
{
    public const ALL = 'all';

    public const ANY = 'any';

    /**
     * @param  self::ALL|self::ANY  $match
     * @param  list<string>  $abilities
     */
    public function __construct(
        public string $match,
        public array $abilities,
    ) {}

    /**
     * @return array{match: string, abilities: list<string>}
     */
    public function toArray(): array
    {
        return ['match' => $this->match, 'abilities' => $this->abilities];
    }

    /**
     * The human description line. A single ability reads the same for both match modes; a multi-value
     * `any` says so explicitly ("any of these …") so it doesn't read as an all-of set.
     */
    public function describe(): string
    {
        if (count($this->abilities) === 1) {
            return 'Requires token ability: '.$this->abilities[0];
        }

        $label = $this->match === self::ANY
            ? 'Requires any of these token abilities'
            : 'Requires token abilities';

        return $label.': '.implode(', ', $this->abilities);
    }
}
