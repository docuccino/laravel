<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\AuthAttributesController;
use Workbench\App\Http\Controllers\FormController;

/*
 * The security family through the real adapter pipeline, on the two paths an application actually
 * reaches it by: `#[Security]` on an action, and `security.default` in config. Uses the shared
 * `generateDocument()` / `bindStubEngine()` (tests/Pest.php).
 */

it('reports an operation naming a scheme nothing defines, and still publishes the reference', function (): void {
    /** @var Router $router */
    $router = app('router');
    // #[Security('oauth2', ['reports.read'])] + #[Security('apiKey')], with no security.schemes config.
    $router->get('api/reports', [AuthAttributesController::class, 'reports']);

    bindStubEngine();
    $result = generateDocument();
    $document = $result->document->toArray();

    $reported = diagnosticsCoded($result->diagnostics, 'security.undefined-scheme');

    expect($reported)->toHaveCount(2)
        ->and($reported[0]->severity->value)->toBe('error')
        ->and($reported[0]->message)->toContain('"apiKey"')
        ->and($reported[1]->message)->toContain('"oauth2"')
        ->and($reported[0]->message)->toContain('GET /api/reports')
        // The requirement stays: a valid document claiming the endpoint is public would be the worse lie.
        ->and($document['paths']['/api/reports']['get']['security'])->toBe([
            ['oauth2' => ['reports.read']],
            ['apiKey' => []],
        ])
        ->and($document['components'] ?? [])->not->toHaveKey('securitySchemes');
});

it('says nothing once the schemes the operation names are configured', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/reports', [AuthAttributesController::class, 'reports']);

    bindStubEngine();
    $result = generateDocument(static function (array $raw): array {
        $raw['security']['schemes'] = [
            'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            'oauth2' => ['type' => 'oauth2', 'flows' => ['authorizationCode' => [
                'authorizationUrl' => 'https://example.test/oauth/authorize',
                'tokenUrl' => 'https://example.test/oauth/token',
                'scopes' => ['reports.read' => 'Read reports'],
            ]]],
        ];

        return $raw;
    });

    expect(diagnosticsCoded($result->diagnostics, 'security.undefined-scheme'))->toBe([])
        ->and(diagnosticsCoded($result->diagnostics, 'security.undeclared-scope'))->toBe([]);
});

it('reports an OAuth2 scope the configured scheme never declares', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/reports', [AuthAttributesController::class, 'reports']);

    bindStubEngine();
    $result = generateDocument(static function (array $raw): array {
        $raw['security']['schemes'] = [
            'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            // The flow declares a neighbouring scope, not the one the action asks for.
            'oauth2' => ['type' => 'oauth2', 'flows' => ['authorizationCode' => [
                'authorizationUrl' => 'https://example.test/oauth/authorize',
                'tokenUrl' => 'https://example.test/oauth/token',
                'scopes' => ['reports.write' => 'Write reports'],
            ]]],
        ];

        return $raw;
    });

    $reported = diagnosticsCoded($result->diagnostics, 'security.undeclared-scope');

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->severity->value)->toBe('warning')
        ->and($reported[0]->message)->toContain('"reports.read"')
        ->and($reported[0]->help)->toContain('(reports.write)');
});

it('reports a config default requirement naming a scheme the catalogue is short of', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/protected', [FormController::class, 'index'])->middleware('auth');

    bindStubEngine();
    $result = generateDocument(static function (array $raw): array {
        $raw['security']['schemes'] = ['bearer' => ['type' => 'http', 'scheme' => 'bearer']];
        $raw['security']['default'] = [['bearer' => [], 'apiKey' => []]];

        return $raw;
    });

    $reported = diagnosticsCoded($result->diagnostics, 'security.undefined-scheme');

    // One report for the name, not one per operation the default reached.
    expect($reported)->toHaveCount(1)
        ->and($reported[0]->message)->toContain('"apiKey"')
        ->and($reported[0]->help)->toContain('(bearer)');
});

/*
 * The one malformed shape an author actually writes by hand: `security: [["bearer"]]` puts the scheme
 * where the scopes go, so the entry states no scheme name at all. The audit says nothing about it — a
 * positional key is not a scheme name, and reading it as one invented the scheme "0" and failed the
 * build over a typo nobody had made. The mistake is still reported, once, by the check positioned to
 * locate it: the schema validation, at the pointer.
 */
it('leaves a list-shaped requirement to the schema check rather than inventing a scheme from its position', function (): void {
    // The workbench already routes an auth-guarded action, so `security.default` reaches an operation.
    bindStubEngine();
    $result = generateDocument(static function (array $raw): array {
        $raw['security']['schemes'] = ['bearer' => ['type' => 'http', 'scheme' => 'bearer']];
        // The list around the scheme name is the author error: OAS wants `[['bearer' => []]]`.
        $raw['security']['default'] = [['bearer']];

        return $raw;
    });

    $invalid = diagnosticsCoded($result->diagnostics, 'document.schema-invalid');

    expect(diagnosticsCoded($result->diagnostics, 'security.undefined-scheme'))->toBe([])
        ->and(diagnosticsCoded($result->diagnostics, 'security.undeclared-scope'))->toBe([])
        // ...and the author is told, at error severity, exactly where the malformed entry sits.
        ->and($invalid)->not->toBeEmpty()
        ->and($invalid[0]->severity->value)->toBe('error')
        ->and(implode("\n", array_map(static fn ($d): string => $d->message, $invalid)))
        ->toContain('/get/security/0 The data (array) must match the type: object');
});

it('says nothing about the workbench document as it stands', function (): void {
    bindStubEngine();
    $result = generateDocument();

    expect(diagnosticsCoded($result->diagnostics, 'security.undefined-scheme'))->toBe([])
        ->and(diagnosticsCoded($result->diagnostics, 'security.undeclared-scope'))->toBe([])
        // Anti-vacuity: a build that raised nothing at all would agree with both assertions.
        ->and($result->diagnostics)->not->toBeEmpty();
});
