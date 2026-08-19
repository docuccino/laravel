<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Support\BinaryRepresentation;
use Docuccino\Laravel\Support\EventStreamRepresentation;
use Docuccino\Laravel\Support\FrameworkClasses;
use Docuccino\Laravel\Tests\Support\TraceScript;

/**
 * The real-analyser half of the file/stream recovery. A stub scope can prove the visitor's arithmetic;
 * only the real engine proves that `response()->download(…)` and `Storage::download(…)` reach it as the
 * types and receiver types it matches on, that `eventStream()` resolves at all (it is not on the response
 * factory CONTRACT the helper is typed as), and that a path helper leaves the extension readable.
 *
 * The pair per action is the point: the return TYPE is the same for a download and for an inline file, so
 * everything that distinguishes them comes from the call.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('recovers the media type and disposition of an idiomatic file or stream call', function (
    string $method,
    string $returnClass,
    string $mediaType,
    ?string $disposition,
    ?string $filename,
): void {
    $relPath = 'app/Http/Controllers/FileDeliveryController.php';
    $class = 'App\\Http\\Controllers\\FileDeliveryController';

    $returnType = ActionAnalysis::fromArray(FixtureRunner::analyze($relPath, $class, $method))->returns[0]->type ?? null;
    $calls = FixtureRunner::traceFileResponses($relPath, $class, $method);

    expect($returnType)->toBeInstanceOf(ClassT::class)
        ->and($returnType->fqcn)->toBe($returnClass)
        ->and($returnType->typeArgs)->toBe([])
        ->and($calls)->toHaveCount(1)
        ->and($calls[0]['responseClass'])->toBe($returnClass)
        ->and($calls[0]['mediaType'])->toBe($mediaType)
        ->and($calls[0]['disposition'])->toBe($disposition)
        ->and($calls[0]['filename'])->toBe($filename);
})->with([
    // `storage_path(…)` is a call PHPStan does not fold, and the extension survives it anyway.
    'download' => ['download', FrameworkClasses::BINARY_FILE_RESPONSE, 'application/pdf', 'attachment', 'invoices.pdf'],
    // Same class, same file, no disposition — the one difference is the call.
    'file' => ['preview', FrameworkClasses::BINARY_FILE_RESPONSE, 'application/pdf', null, 'invoices.pdf'],
    'stream' => ['export', FrameworkClasses::STREAMED_RESPONSE, 'text/csv', null, null],
    // The name is stated and the media type is not, which is the case worth a diagnostic.
    'streamDownload' => ['ledger', FrameworkClasses::STREAMED_RESPONSE, BinaryRepresentation::ANY_MEDIA_TYPE, 'attachment', 'ledger.csv'],
    'eventStream' => ['events', FrameworkClasses::STREAMED_RESPONSE, EventStreamRepresentation::MEDIA_TYPE, null, null],
    // A disk download is a STREAMED response, not a binary one — recovering it the other way round
    // would file the body under a class the return type never matches.
    'storage download' => ['invoice', FrameworkClasses::STREAMED_RESPONSE, 'application/pdf', 'attachment', 'invoice-2026.pdf'],
])->group('fixture');

it('reads nothing off an action that builds no file or stream response', function (): void {
    // The negative half, on real code: `response('ok')` is a response too, and nothing about it belongs
    // to this recovery.
    $calls = FixtureRunner::traceFileResponses(
        'app/Http/Controllers/FileDeliveryController.php',
        'App\\Http\\Controllers\\FileDeliveryController',
        'health',
    );

    expect($calls)->toBe([]);
})->group('fixture');

it('documents a really-recovered download as its own media type with no diagnostic', function (): void {
    // End to end over the type the analyser genuinely produced and the call it genuinely reached: the
    // 200 carries a `application/pdf` binary body and a Content-Disposition, and nothing is announced
    // as lost because nothing is.
    $returnType = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/FileDeliveryController.php',
        'App\\Http\\Controllers\\FileDeliveryController',
        'download',
    ))->returns[0]->type ?? null;

    [$responses, $document, $diagnostics] = documentForReturn(
        $returnType,
        trace: TraceScript::forChain(
            "response()->download(storage_path('app/exports/invoices.pdf'))",
            'Illuminate\\Contracts\\Routing\\ResponseFactory',
        ),
    );

    expect(array_keys($responses['200']['content']))->toBe(['application/pdf'])
        ->and($responses['200']['headers']['Content-Disposition']['example'])->toBe('attachment; filename="invoices.pdf"')
        ->and(typeSchemas($document))->toBe([])
        ->and(array_map(static fn ($d): string => $d->code, $diagnostics))
        ->not->toContain('inferred-response.payload-unrecoverable');
})->group('fixture');
