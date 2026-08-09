<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Passport\OAuth2Scheme;
use Docuccino\Laravel\Integrations\Passport\ScopeMiddlewareParser;
use Docuccino\Laravel\Integrations\Passport\ScopeRequirements;

it('splits scope middleware into all-of and any-of across every form', function (array $middleware, array $allOf, array $anyOf): void {
    $requirements = (new ScopeMiddlewareParser)->parse($middleware);

    expect($requirements->allOf)->toBe($allOf)
        ->and($requirements->anyOf)->toBe($anyOf);
})->with([
    'scope: is any-of' => [['scope:read'], [], ['read']],
    'scopes: is all-of, multiple' => [['scopes:read,write'], ['read', 'write'], []],
    'both forms kept apart, deduped in order' => [['scope:read', 'scopes:write,read'], ['write', 'read'], ['read']],
    'spaces trimmed' => [['scopes:read, write'], ['read', 'write'], []],
    'CheckScopes ::using() FQCN is all-of' => [['Laravel\\Passport\\Http\\Middleware\\CheckScopes:read,write'], ['read', 'write'], []],
    'CheckForAnyScope ::using() FQCN is any-of' => [['Laravel\\Passport\\Http\\Middleware\\CheckForAnyScope:read,write'], [], ['read', 'write']],
    'client: alias is all-of' => [['client:read,write'], ['read', 'write'], []],
    'CheckClientCredentials FQCN is all-of' => [['Laravel\\Passport\\Http\\Middleware\\CheckClientCredentials:read,write'], ['read', 'write'], []],
    'CheckClientCredentialsForAnyScope FQCN is any-of' => [['Laravel\\Passport\\Http\\Middleware\\CheckClientCredentialsForAnyScope:read,write'], [], ['read', 'write']],
    'no scope middleware' => [['auth:api'], [], []],
    'scope-like prefix ignored' => [['scoped:read'], [], []],
    'bare client alias carries no scopes' => [['client'], [], []],
]);

it('detects client-credentials middleware even without scopes', function (array $middleware, bool $expected): void {
    expect((new ScopeMiddlewareParser)->hasClientCredentials($middleware))->toBe($expected);
})->with([
    'bare client alias' => [['client'], true],
    'client alias with scope' => [['client:read'], true],
    'CheckClientCredentials FQCN, no params' => [['Laravel\\Passport\\Http\\Middleware\\CheckClientCredentials'], true],
    'CheckClientCredentials FQCN with scope' => [['Laravel\\Passport\\Http\\Middleware\\CheckClientCredentials:read'], true],
    'CheckClientCredentialsForAnyScope FQCN' => [['Laravel\\Passport\\Http\\Middleware\\CheckClientCredentialsForAnyScope:read'], true],
    'scope middleware is not client-credentials' => [['scopes:read'], false],
    'client-like alias ignored' => [['clientele:read'], false],
    'no middleware' => [['auth:api'], false],
]);

it('models all-of as a single requirement and any-of as an OR-list', function (): void {
    expect((new ScopeRequirements(['read', 'write']))->toSecurity('passport'))
        ->toBe([['passport' => ['read', 'write']]]);

    expect((new ScopeRequirements([], ['read', 'write']))->toSecurity('passport'))
        ->toBe([['passport' => ['read']], ['passport' => ['write']]]);

    // Any-of combined with an always-required all-of scope.
    expect((new ScopeRequirements(['base'], ['read', 'write']))->toSecurity('passport'))
        ->toBe([['passport' => ['base', 'read']], ['passport' => ['base', 'write']]]);
});

it('builds an oauth2 scheme over the configured path with only the always-available grants', function (): void {
    $scheme = OAuth2Scheme::passport('https://api.example.com', 'oauth');

    expect($scheme['type'])->toBe('oauth2')
        ->and(array_keys($scheme['flows']))->toBe(['authorizationCode', 'clientCredentials']);

    expect($scheme['flows']['authorizationCode']['authorizationUrl'])->toBe('https://api.example.com/oauth/authorize')
        ->and($scheme['flows']['authorizationCode']['tokenUrl'])->toBe('https://api.example.com/oauth/token')
        ->and($scheme['flows']['clientCredentials']['scopes'])->toBe(['*' => 'Full access to the API']);
});

it('honours a custom passport.path for the endpoint URLs', function (): void {
    $scheme = OAuth2Scheme::passport('https://api.example.com', 'connect');

    expect($scheme['flows']['authorizationCode']['authorizationUrl'])->toBe('https://api.example.com/connect/authorize')
        ->and($scheme['flows']['authorizationCode']['tokenUrl'])->toBe('https://api.example.com/connect/token');
});

it('emits the real scope catalogue in the flow scope map', function (): void {
    $scheme = OAuth2Scheme::passport('https://api.example.com', 'oauth', ['read' => 'Read data', 'write' => 'Write data']);

    expect($scheme['flows']['authorizationCode']['scopes'])->toBe(['read' => 'Read data', 'write' => 'Write data']);
});

it('adds the password and implicit grants only when enabled', function (): void {
    $none = OAuth2Scheme::passport('https://api.example.com');
    expect(array_keys($none['flows']))->toBe(['authorizationCode', 'clientCredentials']);

    $both = OAuth2Scheme::passport('https://api.example.com', 'oauth', [], true, true);
    expect(array_keys($both['flows']))->toContain('password')
        ->and(array_keys($both['flows']))->toContain('implicit');
    expect($both['flows']['implicit'])->not->toHaveKey('tokenUrl');
});

it('normalises a trailing slash on the base URL', function (): void {
    $scheme = OAuth2Scheme::passport('https://api.example.com/');

    expect($scheme['flows']['authorizationCode']['tokenUrl'])->toBe('https://api.example.com/oauth/token');
});
