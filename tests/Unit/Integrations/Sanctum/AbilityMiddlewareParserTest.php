<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Sanctum\AbilityMiddlewareParser;
use Docuccino\Laravel\Integrations\Sanctum\AbilityRequirement;

/**
 * Dataset coverage over every ability-middleware prefix (short aliases + the ability and legacy-scope
 * FQCN forms) and the unknown-entry degradation contract (design coverage standards).
 */
it('parses each ability-middleware form into a match-typed requirement', function (string $middleware, ?string $match, array $abilities): void {
    $requirement = (new AbilityMiddlewareParser)->parse($middleware);

    if ($match === null) {
        expect($requirement)->toBeNull();

        return;
    }

    expect($requirement)->not->toBeNull()
        ->and($requirement->match)->toBe($match)
        ->and($requirement->abilities)->toBe($abilities);
})->with([
    'abilities: is all-of' => ['abilities:read,write', AbilityRequirement::ALL, ['read', 'write']],
    'ability: is any-of' => ['ability:read,write', AbilityRequirement::ANY, ['read', 'write']],
    'abilities: single' => ['abilities:publish', AbilityRequirement::ALL, ['publish']],
    'ability: single' => ['ability:publish', AbilityRequirement::ANY, ['publish']],
    'spaces trimmed, empties dropped' => ['abilities:read, , write', AbilityRequirement::ALL, ['read', 'write']],
    // A colon INSIDE the ability name survives: only the leading prefix is stripped, then the remainder
    // is split on commas — so `abilities:mail:read` is the single ability `mail:read`.
    'colon-bearing ability name preserved' => ['abilities:mail:read', AbilityRequirement::ALL, ['mail:read']],
    'multiple colon-bearing abilities' => ['abilities:mail:read,mail:write', AbilityRequirement::ALL, ['mail:read', 'mail:write']],
    'CheckAbilities FQCN is all-of' => ['Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities:read,write', AbilityRequirement::ALL, ['read', 'write']],
    'CheckForAnyAbility FQCN is any-of' => ['Laravel\\Sanctum\\Http\\Middleware\\CheckForAnyAbility:read', AbilityRequirement::ANY, ['read']],
    'legacy CheckScopes FQCN is all-of' => ['Laravel\\Sanctum\\Http\\Middleware\\CheckScopes:read,write', AbilityRequirement::ALL, ['read', 'write']],
    'legacy CheckForAnyScope FQCN is any-of' => ['Laravel\\Sanctum\\Http\\Middleware\\CheckForAnyScope:read,write', AbilityRequirement::ANY, ['read', 'write']],
    'unknown middleware degrades to null' => ['auth:sanctum', null, []],
    'ability-like prefix ignored' => ['abilityx:read', null, []],
    'empty ability list degrades to null' => ['abilities:', null, []],
]);

it('describes single, all-of and any-of requirements truthfully', function (): void {
    expect((new AbilityRequirement(AbilityRequirement::ALL, ['publish']))->describe())
        ->toBe('Requires token ability: publish');

    expect((new AbilityRequirement(AbilityRequirement::ALL, ['read', 'write']))->describe())
        ->toBe('Requires token abilities: read, write');

    expect((new AbilityRequirement(AbilityRequirement::ANY, ['read', 'write']))->describe())
        ->toBe('Requires any of these token abilities: read, write');
});
