<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Fixtures\ComponentNames\ClaimController;
use Docuccino\Laravel\Tests\Support\LocalityEngine;
use Docuccino\Laravel\Tests\Support\ThrowingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * Pipeline failure semantics (design §5): --fail-on exit-code matrix, the validate command's
 * schema-violation failure, per-route engine-exception isolation, and component rollback.
 */
it('honours the --fail-on matrix against the broken route', function (string $failOn, bool $fails): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
    $out = sys_get_temp_dir().'/docuccino-failon-'.uniqid().'.json';

    $command = $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => $failOn]);
    $fails ? $command->assertFailed() : $command->assertSuccessful();

    @unlink($out);
})->with([
    'none exits 0' => ['none', false],
    'hint exits non-zero (the broken route is an error)' => ['hint', true],
    'info exits non-zero' => ['info', true],
    'warning exits non-zero' => ['warning', true],
    'error exits non-zero' => ['error', true],
]);

/**
 * The rung `--fail-on` gained, on the only document that can prove it: one route whose loudest
 * report is the `info` an integration raises when it had to fall back to package defaults. `warning`
 * cannot see it, which is the whole reason a CI gate on inference certainty needed `info`.
 */
it('lets info gate a build whose loudest report is a recovery', function (string $failOn, bool $fails): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
    config()->set('docuccino.documents.default.routes.include', ['api/widget-query']);
    $out = sys_get_temp_dir().'/docuccino-failon-'.uniqid().'.json';

    $command = $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => $failOn]);
    $fails ? $command->assertFailed() : $command->assertSuccessful();

    @unlink($out);
})->with([
    'none exits 0' => ['none', false],
    'error does not see an info' => ['error', false],
    'warning does not see an info' => ['warning', false],
    'info exits non-zero' => ['info', true],
    'hint exits non-zero (info is louder)' => ['hint', true],
]);

it('rejects an unknown --fail-on rather than quietly never failing', function (string $command): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    // The broken route makes the run fail under every severity; a typo that coerced to "none" would
    // exit 0 here and take the CI gate down with it.
    $this->artisan($command, ['--fail-on' => 'eror'])
        ->expectsOutputToContain('Unknown --fail-on "eror"; expected one of: none, error, warning, info, hint.')
        ->assertFailed();
})->with(['docuccino:export', 'docuccino:validate']);

it('rejects a severity-shaped value that is not one, on both commands', function (string $command, string $value): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    $this->artisan($command, ['--fail-on' => $value])
        ->expectsOutputToContain(sprintf('Unknown --fail-on "%s"', $value))
        ->assertFailed();
})->with(['docuccino:export', 'docuccino:validate'])->with([
    'the severity spelled with a capital' => ['Error'],
    'a confidence number, which this option does not take' => ['0.8'],
    'a diagnostic code, which this option does not take' => ['inferred-response.payload-unrecoverable'],
    'the empty-ish value a shell can produce' => [' '],
    'all, which sounds like a value and is not one' => ['all'],
]);

it('treats a valueless --fail-on as the default', function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
    $out = sys_get_temp_dir().'/docuccino-failon-'.uniqid().'.json';

    // `--fail-on` with nothing after it is not a typo, so it stays what leaving it off means.
    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => null])->assertSuccessful();

    @unlink($out);
});

it('fails validation when a transformer corrupts the document into a schema violation', function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    // A transformer that removes the required top-level `openapi` field breaks the UIR schema.
    Docuccino::extend(new class implements DocumentTransformer
    {
        public function transform(UirDocumentDraft $document, DocumentContext $context): void
        {
            $document->set('openapi', 42); // wrong type → schema violation
        }
    });

    $this->artisan('docuccino:validate')->assertFailed();
});

it('isolates an engine exception to a skeleton while siblings document normally', function (): void {
    $engine = new ThrowingTypeEngine(
        WorkbenchEngine::make(),
        'Workbench\\App\\Http\\Controllers\\FormController::index',
    );
    app()->instance(TypeEngine::class, $engine);

    $config = app(DocumentConfigFactory::class)->make('default', (array) config('docuccino.documents.default'), 'skeleton');
    $result = app(DocumentGenerator::class)->generate($config, $engine);
    $document = $result->document->toArray();

    // The exploding route is present as a skeleton...
    expect($document['paths']['/api/forms']['get']['description'] ?? null)
        ->toBe('Documentation could not be generated for this route.')
        // ...its sibling documents its real response...
        ->and($document['paths']['/api/forms/{form}']['get']['responses'])->toHaveKey('200')
        // ...and an error diagnostic names the failed route.
        ->and(array_filter(
            $result->diagnostics,
            static fn ($d): bool => $d->code === 'route.build-failed' && $d->routeSignature === 'GET /api/forms',
        ))->not->toBeEmpty();
});

it('rolls back components registered by a route that then throws (A3)', function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    // An extension that, only for /api/ping, registers a component and then throws mid-pipeline.
    Docuccino::extend(new class implements OperationExtension
    {
        public function phase(): OperationPhase
        {
            return OperationPhase::Finalize;
        }

        public function handle(OperationDraft $operation, RouteContext $context): void
        {
            if ($context->route->uri !== '/api/ping') {
                return;
            }

            $context->components->registerSchema('OrphanFromPing', ['type' => 'object'], 'App\\Orphan');

            throw new RuntimeException('boom after registering a component');
        }
    });

    $document = generateDocument()->document->toArray();

    // The failed route left no orphaned component behind...
    expect($document['components']['schemas'] ?? [])->not->toHaveKey('OrphanFromPing')
        // ...while a healthy route's real component survives.
        ->and($document['components']['schemas'] ?? [])->toHaveKey('FormData');
});

it('cannot rename a neighbour by registering a component and then throwing', function (): void {
    // The other half of the rollback claim. The row above proves the failed route leaves no ORPHAN; a
    // registration is also a CLAIM on a name, and a claim that outlives its route moves the component a
    // healthy neighbour was already published under — a rename performed by a route that documented
    // nothing at all, with no collision left in the document to explain it.
    assertUnaffectedByUnrelatedRoute(
        static fn (Router $router) => $router->get('api/zz-portal', [ClaimController::class, 'show']),
        static function (Router $router): void {
            $router->get('api/zz-doomed', [ClaimController::class, 'show']);

            Docuccino::extend(new class implements OperationExtension
            {
                public function phase(): OperationPhase
                {
                    return OperationPhase::Finalize;
                }

                public function handle(OperationDraft $operation, RouteContext $context): void
                {
                    if ($context->route->uri !== '/api/zz-doomed') {
                        return;
                    }

                    // A different class asking for the name the neighbour holds: contested, both claims
                    // climb, and the neighbour's `$ref` moves with them.
                    $context->components->registerSchema('Portal', ['type' => 'string'], 'App\\Doomed\\PortalData');

                    throw new RuntimeException('boom after claiming a name');
                }
            });
        },
        'GET /api/zz-portal',
        LocalityEngine::factory(),
    );
});
