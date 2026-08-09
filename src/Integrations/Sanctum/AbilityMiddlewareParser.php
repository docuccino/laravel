<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

/**
 * Parses a Sanctum ability middleware string into an {@see AbilityRequirement}. Sanctum ships `abilities`
 * (`CheckAbilities`, all-of) and `ability` (`CheckForAnyAbility`, any-of) as short aliases, plus the
 * deprecated `CheckScopes`/`CheckForAnyScope` that delegate to them — those register no alias, so routes
 * name the FQCN directly. Every form takes a comma-separated list; null for anything else. Pure, so the
 * middleware map is dataset-testable.
 */
final class AbilityMiddlewareParser
{
    /**
     * Prefix → match semantics, longest alias first so no short alias shadows another.
     *
     * @var array<string, string>
     */
    private const PREFIXES = [
        'abilities:' => AbilityRequirement::ALL,
        'ability:' => AbilityRequirement::ANY,
        'Laravel\\Sanctum\\Http\\Middleware\\CheckForAnyAbility:' => AbilityRequirement::ANY,
        'Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities:' => AbilityRequirement::ALL,
        'Laravel\\Sanctum\\Http\\Middleware\\CheckForAnyScope:' => AbilityRequirement::ANY,
        'Laravel\\Sanctum\\Http\\Middleware\\CheckScopes:' => AbilityRequirement::ALL,
    ];

    public function parse(string $middleware): ?AbilityRequirement
    {
        foreach (self::PREFIXES as $prefix => $match) {
            if (! str_starts_with($middleware, $prefix)) {
                continue;
            }

            $abilities = array_values(array_filter(
                array_map('trim', explode(',', substr($middleware, strlen($prefix)))),
                static fn (string $a): bool => $a !== '',
            ));

            return $abilities === [] ? null : new AbilityRequirement($match, $abilities);
        }

        return null;
    }
}
