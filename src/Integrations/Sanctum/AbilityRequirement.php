<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

/**
 * One Sanctum token-ability requirement, from an `abilities:`/`ability:` middleware, the legacy scope
 * middleware, or a `#[Abilities]` attribute. Match semantics are `all` for `CheckAbilities`/`abilities:`
 * and `any` for `CheckForAnyAbility`/`ability:`. Feeds the `x-abilities` member and the description line.
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
     * A single ability reads the same either way; a multi-value `any` says so explicitly, or it would read
     * as an all-of set.
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
