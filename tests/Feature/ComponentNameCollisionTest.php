<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\ComponentNames\CollisionController;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * A component name is a class's SHORT name, so two classes sharing one contest the same
 * `#/components/schemas/…` slot. These pin what the build does about it: both shapes survive under
 * names derived from their namespaces, the warning names the two FQCNs so the author can act on it,
 * `#[SchemaName]` settles it, and an author-chosen name is still just a claim — two classes choosing
 * the same one contest it like any other pair.
 *
 * The classes are real and the `#[SchemaName]` reads are real reflection; only the engine that would
 * report their properties is stubbed.
 */
const COLLISION_BILLING_NS = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Billing\\';
const COLLISION_SUPPORT_NS = 'Docuccino\\Laravel\\Tests\\Fixtures\\ComponentNames\\Support\\';

/**
 * Register the two colliding routes (optionally in reverse registration order) and build with an
 * engine that reports each namespace's three DTOs off the matching action.
 */
function collisionDocument(bool $reverseRegistration = false): GenerationResult
{
    $routes = [
        ['api/zz-billing', 'billing'],
        ['api/zz-support', 'support'],
    ];
    // `descriptors()` sorts by "METHOD uri" before processing, so which route the router happens to
    // hold first must not decide which class keeps the plain name.
    foreach ($reverseRegistration ? array_reverse($routes) : $routes as [$uri, $action]) {
        app('router')->get($uri, [CollisionController::class, $action]);
    }

    $shape = static fn (string $namespace): ArrayShapeT => new ArrayShapeT([
        new ArrayShapeField('invoice', new ClassT($namespace.'InvoiceData')),
        new ArrayShapeField('ledger', new ClassT($namespace.'LedgerData')),
        new ArrayShapeField('statement', new ClassT($namespace.'StatementData')),
    ]);
    $analysis = static fn (string $namespace): ActionAnalysis => new ActionAnalysis(
        returns: [new ReturnSite($shape($namespace), new SourceLocation(''))],
    );

    $classes = [];
    foreach ([COLLISION_BILLING_NS => ScalarT::int(), COLLISION_SUPPORT_NS => ScalarT::string()] as $namespace => $type) {
        foreach (['InvoiceData', 'LedgerData', 'StatementData'] as $class) {
            $classes[$namespace.$class] = new ClassMetadata($namespace.$class, [new PropertyMetadata('member', $type)]);
        }
    }

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        classOverrides: $classes,
        analysisOverrides: [
            CollisionController::class.'::billing' => $analysis(COLLISION_BILLING_NS),
            CollisionController::class.'::support' => $analysis(COLLISION_SUPPORT_NS),
        ],
    ));

    return generateDocument();
}

/**
 * @return array<string, string> the `$ref`s of one collision route's response, by member name
 */
function collisionRefs(GenerationResult $result, string $path): array
{
    $properties = $result->document->toArray()['paths'][$path]['get']['responses']['200']['content']['application/json']['schema']['properties'] ?? [];

    return array_map(static fn (array $property): string => (string) ($property['$ref'] ?? ''), $properties);
}

it('keeps both shapes, under names naming their namespaces, when two classes share a short name', function (): void {
    // The outcome that matters: nothing is overwritten and nothing is merged. Two classes, two
    // components, and each reference site points at its OWN class's shape.
    $result = collisionDocument();
    $schemas = $result->document->toArray()['components']['schemas'] ?? [];

    expect($schemas)->toHaveKeys(['BillingInvoiceData', 'SupportInvoiceData'])
        ->and($schemas['BillingInvoiceData']['properties']['member']['type'] ?? null)->toBe('integer')
        ->and($schemas['SupportInvoiceData']['properties']['member']['type'] ?? null)->toBe('string')
        ->and(collisionRefs($result, '/api/zz-billing')['invoice'] ?? null)->toBe('#/components/schemas/BillingInvoiceData')
        ->and(collisionRefs($result, '/api/zz-support')['invoice'] ?? null)->toBe('#/components/schemas/SupportInvoiceData');
});

it('names both classes and the contested name in the collision warning', function (): void {
    // "Two schemas collided" is unactionable in an app with hundreds of DTOs — the short name in the
    // message is precisely the one that identifies neither claimant.
    $result = collisionDocument();
    $warning = diagnosticsCoded($result->diagnostics, 'components.name-collision');

    $invoice = array_values(array_filter($warning, static fn ($d): bool => str_contains($d->message, 'InvoiceData')));

    expect($invoice)->toHaveCount(1)
        ->and($invoice[0]->severity->value)->toBe('warning')
        ->and($invoice[0]->message)
        ->toContain('"InvoiceData"')
        ->toContain(COLLISION_BILLING_NS.'InvoiceData as "BillingInvoiceData"')
        ->toContain(COLLISION_SUPPORT_NS.'InvoiceData as "SupportInvoiceData"')
        ->and($invoice[0]->help)->toContain('#[SchemaName]');
});

it('settles a collision when #[SchemaName] renames one of the classes', function (): void {
    // The escape hatch, proven through real attribute reflection: the attributed class takes its
    // chosen name and the twin keeps the plain one, so neither is qualified and neither warns.
    $result = collisionDocument();
    $schemas = $result->document->toArray()['components']['schemas'] ?? [];
    $warned = array_filter(
        diagnosticsCoded($result->diagnostics, 'components.name-collision'),
        static fn ($d): bool => str_contains($d->message, 'LedgerData'),
    );

    expect($schemas)->toHaveKeys(['BillingLedger', 'LedgerData'])
        ->and($schemas)->not->toHaveKey('LedgerData_2')
        ->and($schemas)->not->toHaveKey('SupportLedgerData')
        ->and($warned)->toBeEmpty()
        ->and(collisionRefs($result, '/api/zz-billing')['ledger'] ?? null)->toBe('#/components/schemas/BillingLedger');
});

it('still collides when two classes claim the SAME #[SchemaName]', function (): void {
    // An author-chosen name is a claim on the same namespace, not an exemption from it.
    $result = collisionDocument();
    $schemas = $result->document->toArray()['components']['schemas'] ?? [];
    $warning = array_values(array_filter(
        diagnosticsCoded($result->diagnostics, 'components.name-collision'),
        static fn ($d): bool => str_contains($d->message, '"Statement"'),
    ));

    expect($schemas)->toHaveKeys(['BillingStatement', 'SupportStatement'])
        ->and($schemas)->not->toHaveKey('Statement')
        ->and($warning)->toHaveCount(1)
        ->and($warning[0]->message)
        ->toContain(COLLISION_BILLING_NS.'StatementData as "BillingStatement"')
        ->toContain(COLLISION_SUPPORT_NS.'StatementData as "SupportStatement"');
});

it('names the components the same way however the routes were registered', function (): void {
    // The names come off the FQCNs, so neither route order nor the router's own ordering can reach
    // them. Two full exports, byte for byte.
    $forwards = (new UirEmitter)->emit(collisionDocument()->document);
    $backwards = (new UirEmitter)->emit(collisionDocument(reverseRegistration: true)->document);

    expect($backwards)->toBe($forwards)
        ->and($forwards)->toContain('SupportInvoiceData');
});

it('still reports the collision on a warm fragment-cache build', function (): void {
    // A warm hit restores components instead of registering them, so a registration-time report would
    // vanish from a build whose bytes still carry the suffix. The fragment replays it instead.
    $dir = sys_get_temp_dir().'/docuccino-fragments-'.uniqid('', true);
    config()->set('docuccino.cache.enabled', true);
    config()->set('docuccino.cache.path', $dir);

    $cold = collisionDocument();
    $warm = collisionDocument();

    expect((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document))
        ->and(diagnosticsCoded($warm->diagnostics, 'components.name-collision'))
        ->toEqual(diagnosticsCoded($cold->diagnostics, 'components.name-collision'))
        ->not->toBeEmpty();

    array_map('unlink', glob($dir.'/*') ?: []);
    @unlink($dir.'/.gitignore');
    @rmdir($dir);
});
