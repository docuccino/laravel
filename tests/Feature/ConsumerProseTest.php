<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\ConsumerProseController;
use Workbench\App\Http\Controllers\DescribedController;
use Workbench\App\Http\Controllers\DescriptionEscapeController;

/**
 * The prose an API consumer reads, and how an author says it without rewriting the docblock the next
 * maintainer relies on: `@summary`/`@description` at the docblock rung, `#[Summary]`/`#[Description]`
 * above it, and `#[Description(file: …)]` confined to the application root (security L2).
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

function describeRoutes(callable $routes): array
{
    /** @var Router $router */
    $router = app('router');
    $routes($router);

    $config = app(DocumentConfigFactory::class)->make('default', (array) config('docuccino.documents.default'), 'skeleton');

    return (function () use ($config) {
        $result = app(DocumentGenerator::class)->generate($config, app(TypeEngine::class));

        return ['paths' => $result->document->toArray()['paths'], 'diagnostics' => $result->diagnostics];
    })();
}

it('rejects a #[Description(file:)] path that escapes the base with an error diagnostic', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->get('api/escape', [DescriptionEscapeController::class, 'index']);
    });

    // No description was loaded from the out-of-tree file...
    expect($result['paths']['/api/escape']['get']['description'] ?? null)->toBeNull();

    // ...and the escape raised an error diagnostic.
    $escape = diagnosticsCoded($result['diagnostics'], 'description-file.escapes-base-path');
    expect($escape)->not->toBeEmpty()
        ->and($escape[0]->severity)->toBe(Severity::Error);
});

it('loads an in-tree #[Description(file:)] into the operation description', function (): void {
    $absolute = base_path('docuccino-described.md');
    file_put_contents($absolute, "# Described\n\nBody prose.\n");

    $result = describeRoutes(function (Router $router): void {
        $router->get('api/described', [DescribedController::class, 'index']);
    });

    expect($result['paths']['/api/described']['get']['description'] ?? null)->toBe("# Described\n\nBody prose.")
        ->and($result['diagnostics'])->toBeArray();

    @unlink($absolute);
});

// Determinism is byte-identical output for identical code, and a line ending is not a code change:
// the same markdown checked out on Windows and on Linux has to emit the same description.
it('emits the same bytes whether the described file is CRLF or LF', function (): void {
    $absolute = base_path('docuccino-described.md');

    /** @var Router $router */
    $router = app('router');
    $router->get('api/described', [DescribedController::class, 'index']);

    $config = app(DocumentConfigFactory::class)->make('default', (array) config('docuccino.documents.default'), 'skeleton');

    $emit = static function () use ($config): string {
        $document = app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document;

        return json_encode($document->toArray(), JSON_THROW_ON_ERROR);
    };

    file_put_contents($absolute, "# Described\r\n\r\nBody prose.\r\n");
    $crlf = $emit();

    file_put_contents($absolute, "# Described\n\nBody prose.\n");
    $lf = $emit();

    @unlink($absolute);

    // The LF spelling is what lands, so a stale cache serving the CRLF build back fails here too.
    expect($crlf)->toContain('# Described\n\nBody prose.')
        ->and($crlf)->toBe($lf);
});

it('says so when a #[Description(file:)] names a file that is not there', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->get('api/absent', [ConsumerProseController::class, 'absentFile']);
    });

    $missing = diagnosticsCoded($result['diagnostics'], 'description-file.missing');

    expect($result['paths']['/api/absent']['get']['description'] ?? null)->toBeNull()
        ->and($missing)->not->toBeEmpty()
        ->and($missing[0]->severity)->toBe(Severity::Warning);
});

it('publishes #[Summary] and #[Description] over the docblock the maintainer reads', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->get('api/attributed', [ConsumerProseController::class, 'attributed']);
    });

    $operation = $result['paths']['/api/attributed']['get'];

    expect($operation['summary'])->toBe('Create an invoice')
        ->and($operation['description'])->toBe('Creates a draft invoice for the authenticated tenant.');
});

it('publishes @summary and @description over the prose above them', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->get('api/tagged', [ConsumerProseController::class, 'tagged']);
    });

    $operation = $result['paths']['/api/tagged']['get'];

    expect($operation['summary'])->toBe('Void an invoice')
        ->and($operation['description'])->toBe('Marks an invoice void. Voiding is permanent and cannot be undone.');
});

// One tag is enough to hand the whole consumer-facing text over: the paragraphs above it were written
// for whoever maintains the action, and half of a note like that is worse in a document than none.
it('drops the free prose entirely once either tag is declared', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->get('api/summary-only', [ConsumerProseController::class, 'summaryTagOnly']);
    });

    $operation = $result['paths']['/api/summary-only']['get'];

    expect($operation['summary'])->toBe('Send an invoice')
        ->and($operation['description'] ?? null)->toBeNull();
});

it('documents nothing and says why when #[Description] names both text and a file', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->get('api/contradictory', [ConsumerProseController::class, 'contradictory']);
    });

    $unusable = diagnosticsCoded($result['diagnostics'], 'attribute.description-unusable');

    expect($result['paths']['/api/contradictory']['get']['description'] ?? null)->toBeNull()
        ->and($unusable)->not->toBeEmpty()
        ->and($unusable[0]->severity)->toBe(Severity::Warning)
        ->and($unusable[0]->message)->toContain('both `text:` and `file:`');
});

it('documents nothing and says why when #[Description] names neither', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->get('api/empty', [ConsumerProseController::class, 'empty']);
    });

    $unusable = diagnosticsCoded($result['diagnostics'], 'attribute.description-unusable');

    expect($result['paths']['/api/empty']['get']['description'] ?? null)->toBeNull()
        ->and($unusable)->not->toBeEmpty()
        ->and($unusable[0]->severity)->toBe(Severity::Warning)
        ->and($unusable[0]->message)->toContain('neither `text:` nor `file:`');
});

it('serves a warm build exactly what a cold one would, prose and diagnostics both', function (): void {
    // The attributes are read while a fragment is built and their complaints are raised there, so a
    // warm hit that reassembles rather than rebuilds has to replay both — fewer diagnostics on a warm
    // build is a silent degradation, not a saving.
    $warm = assertWarmEqualsCold(
        static function (Router $router): void {
            $router->get('api/attributed', [ConsumerProseController::class, 'attributed']);
            $router->get('api/absent', [ConsumerProseController::class, 'absentFile']);
            $router->get('api/empty', [ConsumerProseController::class, 'empty']);
        },
        static function (Router $router): void {
            $router->get('api/attributed', [ConsumerProseController::class, 'attributed']);
            $router->get('api/absent', [ConsumerProseController::class, 'absentFile']);
            $router->get('api/empty', [ConsumerProseController::class, 'empty']);
            $router->get('api/tagged', [ConsumerProseController::class, 'tagged']);
        },
    );

    // …and it really did replay them, rather than both builds being equally silent.
    expect(diagnosticsCoded($warm->diagnostics, 'description-file.missing'))->not->toBeEmpty()
        ->and(diagnosticsCoded($warm->diagnostics, 'attribute.description-unusable'))->not->toBeEmpty()
        ->and($warm->document->toArray()['paths']['/api/attributed']['get']['summary'])->toBe('Create an invoice');
});
