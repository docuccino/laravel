<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\ConsumerProseController;
use Workbench\App\Http\Controllers\DescribedController;
use Workbench\App\Http\Controllers\DescriptionEscapeController;
use Workbench\App\Http\Controllers\SharedBodyProseController;

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

/*
 * The same file, configured rather than declared. `info.description.file` is read at config time and
 * had ONE voice for three different outcomes — a refused path, an unreadable file, and a document that
 * simply never configured a description — which is no voice at all: the author is left with an `info`
 * object missing its description and no reason why.
 */
it('says so when info.description.file does not name a path inside the application', function (): void {
    config()->set('docuccino.documents.default.info.description', ['file' => '../../../etc/passwd']);

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    $escaped = diagnosticsCoded($result->diagnostics, 'description-file.escapes-base-path');

    expect($result->document->toArray()['info']['description'] ?? null)->toBeNull()
        ->and($escaped)->toHaveCount(1)
        ->and($escaped[0]->severity)->toBe(Severity::Error)
        ->and($escaped[0]->message)->toContain('info.description.file')
        ->and($escaped[0]->help)->toBe(ConfinedPath::CONFIG_FILE_ESCAPED_HELP);
});

it('says so when info.description.file names a file that is not there', function (): void {
    config()->set('docuccino.documents.default.info.description', ['file' => 'resources/docs/nowhere.md']);

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    $missing = diagnosticsCoded($result->diagnostics, 'description-file.missing');

    expect($result->document->toArray()['info']['description'] ?? null)->toBeNull()
        ->and($missing)->toHaveCount(1)
        ->and($missing[0]->severity)->toBe(Severity::Warning)
        ->and($missing[0]->message)->toContain('resources/docs/nowhere.md')
        ->and($missing[0]->help)->toBe(ConfinedPath::CONFIG_FILE_MISSING_HELP);
});

it('says so when info.description.file holds a byte no filesystem path can', function (): void {
    // The third refusal `ConfinedPath` makes, and the one that used to be indistinguishable from the
    // other two. It is a refusal like a traversal, not an absence, so it reports as one.
    config()->set('docuccino.documents.default.info.description', ['file' => "resources/docs\0/api.md"]);

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    $escaped = diagnosticsCoded($result->diagnostics, 'description-file.escapes-base-path');

    expect($escaped)->toHaveCount(1)
        // The offending value is the author's own text on its way into a published message.
        ->and($escaped[0]->message)->not->toContain("\0");
});

it('says nothing about an info.description.file it read, or one nobody configured', function (): void {
    // The other half of the population: this fires only where an author configured a path, so it can
    // never fire where there is nothing to act on.
    $absolute = base_path('docuccino-configured-description.md');
    file_put_contents($absolute, "Prose for whoever reads the document.\n");

    config()->set('docuccino.documents.default.info.description', ['file' => 'docuccino-configured-description.md']);
    $read = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    @unlink($absolute);

    config()->set('docuccino.documents.default.info.description', 'Written inline.');
    $inline = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    foreach ([$read, $inline] as $result) {
        expect(diagnosticsCoded($result->diagnostics, 'description-file.escapes-base-path'))->toBe([])
            ->and(diagnosticsCoded($result->diagnostics, 'description-file.missing'))->toBe([]);
    }

    expect($read->document->toArray()['info']['description'])->toBe('Prose for whoever reads the document.')
        ->and($inline->document->toArray()['info']['description'])->toBe('Written inline.');
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
            $router->post('api/described-body', [ConsumerProseController::class, 'describedBody']);
            $router->post('api/bodyless-body-prose', [ConsumerProseController::class, 'bodylessBodyProse']);
        },
        static function (Router $router): void {
            $router->get('api/attributed', [ConsumerProseController::class, 'attributed']);
            $router->get('api/absent', [ConsumerProseController::class, 'absentFile']);
            $router->get('api/empty', [ConsumerProseController::class, 'empty']);
            $router->post('api/described-body', [ConsumerProseController::class, 'describedBody']);
            $router->post('api/bodyless-body-prose', [ConsumerProseController::class, 'bodylessBodyProse']);
            $router->get('api/tagged', [ConsumerProseController::class, 'tagged']);
        },
    );

    // …and it really did replay them, rather than both builds being equally silent.
    expect(diagnosticsCoded($warm->diagnostics, 'description-file.missing'))->not->toBeEmpty()
        ->and(diagnosticsCoded($warm->diagnostics, 'attribute.description-unusable'))->not->toBeEmpty()
        ->and($warm->document->toArray()['paths']['/api/attributed']['get']['summary'])->toBe('Create an invoice')
        // The body prose is reassembled on a warm hit, and the complaint about the one with no body to
        // sit on is replayed with it.
        ->and($warm->document->toArray()['paths']['/api/described-body']['post']['requestBody']['description'])
        ->toBe('Send every field: a widget is replaced wholesale rather than merged.')
        ->and(array_filter(
            diagnosticsCoded($warm->diagnostics, 'attribute.description-unusable'),
            static fn (object $d): bool => str_contains($d->message, 'this operation documents none'),
        ))->not->toBeEmpty();
});

/*
 * The request body's own prose. `requestBody.description` is a different fact from the schema's
 * description: the schema says what the type IS and every operation sharing the component reads it,
 * while this says how THIS operation wants the body filled in. `#[Description(request: true)]` is the
 * only thing that writes it, and it has to survive whichever producer assembled the body.
 */
it('writes #[Description(request: true)] onto the operation request body', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->post('api/described-body', [ConsumerProseController::class, 'describedBody']);
    });

    $body = $result['paths']['/api/described-body']['post']['requestBody'];

    expect($body['description'])->toBe('Send every field: a widget is replaced wholesale rather than merged.')
        // On the body, not on the shared component: the component is the type, which other operations read.
        ->and($body['content']['multipart/form-data']['schema'])->toBe(['$ref' => '#/components/schemas/StoreWidgetRequest'])
        ->and(diagnosticsCoded($result['diagnostics'], 'attribute.description-unusable'))->toBe([]);
});

it('keeps the operation description and the body description apart on one action', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->post('api/described-body', [ConsumerProseController::class, 'describedBody']);
    });

    $operation = $result['paths']['/api/described-body']['post'];

    // Neither declaration takes the other's slot, and neither is duplicated into it.
    expect($operation['description'])->toBe('Creates a widget from the whole submitted body.')
        ->and($operation['requestBody']['description'])->toBe('Send every field: a widget is replaced wholesale rather than merged.');
});

// The attribute layer is where #[BodyParameter] writes the whole body too, in an earlier phase — so a
// description contesting that guarded field would be shadowed and lost. It rides on the body instead.
it('survives a body #[BodyParameter] assembled at its own layer', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->post('api/attribute-body', [ConsumerProseController::class, 'describedAttributeBody']);
    });

    $body = $result['paths']['/api/attribute-body']['post']['requestBody'];

    expect($body['description'])->toBe('One field, and the whole widget is voided.')
        ->and($body['content']['application/json']['schema']['properties'])->toHaveKey('reason');
});

it('loads an in-tree #[Description(file:, request: true)] into the body description', function (): void {
    $absolute = base_path('docuccino-body-prose.md');
    file_put_contents($absolute, "Fill in every field.\n\nThe widget is replaced wholesale.\n");

    try {
        $result = describeRoutes(function (Router $router): void {
            $router->post('api/described-body-file', [ConsumerProseController::class, 'describedBodyFromFile']);
        });
    } finally {
        @unlink($absolute);
    }

    expect($result['paths']['/api/described-body-file']['post']['requestBody']['description'])
        ->toBe("Fill in every field.\n\nThe widget is replaced wholesale.");
});

it('says so when a #[Description(request: true)] has no request body to describe', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->post('api/bodyless-body-prose', [ConsumerProseController::class, 'bodylessBodyProse']);
    });

    $operation = $result['paths']['/api/bodyless-body-prose']['post'];
    $unusable = diagnosticsCoded($result['diagnostics'], 'attribute.description-unusable');

    expect($operation)->not->toHaveKey('requestBody')
        // It describes the body, so it never falls back to describing the operation.
        ->and($operation['description'] ?? null)->toBeNull()
        ->and($unusable)->not->toBeEmpty()
        ->and($unusable[0]->severity)->toBe(Severity::Warning)
        ->and($unusable[0]->message)->toContain('this operation documents none');
});

// A read verb turns recovered rules into query parameters rather than a body, so the same declaration
// on a GET has nothing to describe — and says so, rather than inventing a body for the prose to sit on.
it('reports rather than conjuring a body when the verb makes the rules query parameters', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->get('api/described-body', [ConsumerProseController::class, 'describedBody']);
    });

    $operation = $result['paths']['/api/described-body']['get'];
    $names = array_column($operation['parameters'], 'name');

    expect($operation)->not->toHaveKey('requestBody')
        ->and($names)->toContain('name')
        // The operation's own #[Description] is unaffected by its sibling being undeliverable.
        ->and($operation['description'])->toBe('Creates a widget from the whole submitted body.')
        ->and(diagnosticsCoded($result['diagnostics'], 'attribute.description-unusable'))->not->toBeEmpty();
});

it('documents nothing and says which declaration when the request form names both text and a file', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->post('api/contradictory-body', [ConsumerProseController::class, 'contradictoryBody']);
    });

    $unusable = diagnosticsCoded($result['diagnostics'], 'attribute.description-unusable');

    expect($result['paths']['/api/contradictory-body']['post']['requestBody']['description'] ?? null)->toBeNull()
        ->and($unusable)->not->toBeEmpty()
        ->and($unusable[0]->message)->toContain('A #[Description(request: true)] here carries both `text:` and `file:`');
});

// A declaration on the CONTROLLER covers every action under it, so it lands on the bodies that exist —
// and the actions with no body are not each told about a mistake they are not the place to fix.
it('spreads a controller-level body description over the actions that have a body', function (): void {
    $result = describeRoutes(function (Router $router): void {
        $router->post('api/shared-body', [SharedBodyProseController::class, 'stored']);
        $router->post('api/shared-bodyless', [SharedBodyProseController::class, 'bodyless']);
    });

    expect($result['paths']['/api/shared-body']['post']['requestBody']['description'])
        ->toBe('Send the whole widget; every action here replaces rather than merges.')
        ->and($result['paths']['/api/shared-bodyless']['post'])->not->toHaveKey('requestBody')
        ->and(diagnosticsCoded($result['diagnostics'], 'attribute.description-unusable'))->toBe([]);
});
