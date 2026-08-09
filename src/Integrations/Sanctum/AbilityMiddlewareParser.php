<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

/**
 * Parses a Sanctum ability middleware string into an {@see AbilityRequirement}. Sanctum ships
 * `abilities` (`CheckAbilities`, ALL-of) and `ability` (`CheckForAnyAbility`, ANY-of) as short
 * aliases, plus the deprecated `CheckScopes` (ALL-of) / `CheckForAnyScope` (ANY-of) that delegate to
 * them — none register short aliases, so a route uses the class FQCN directly. All forms take a
 * comma-separated ability list; anything else returns null. Pure so the middleware map is
 * dataset-tested.
 */
final class AbilityMiddlewareParser
{
    /**
     * Prefix → match semantics. Ordered longest-alias-first so no short alias shadows another; the
     * ability and legacy-scope FQCN forms map to the same two match modes.
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
