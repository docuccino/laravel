<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Extensions\FileResponseVisitor;
use Docuccino\Laravel\Support\BinaryRepresentation;
use Docuccino\Laravel\Support\EventStreamRepresentation;
use Docuccino\Laravel\Support\FileMediaTypes;
use Docuccino\Laravel\Support\FrameworkClasses;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Workbench\App\Http\Controllers\DownloadController;

/**
 * A file download, a stream and an event stream all reach the pipeline as the same two bare Symfony
 * classes, so the return TYPE cannot tell them apart — the CALL that built the response can, and these
 * pin both halves: what each recovered call proves, and what the class alone falls back to when no call
 * was reached.
 *
 * That the engine really hands those classes back for the idiomatic calls, and that the visitor really
 * reads them off the analyser's own scope, is proven separately in BinaryResponseReachabilityTest.
 */
const BINARY_RESPONSE_FACTORY = 'Illuminate\\Contracts\\Routing\\ResponseFactory';

const BINARY_RESPONSE_FILESYSTEM = 'Illuminate\\Filesystem\\FilesystemAdapter';

/** A scripted walk over one response-building expression, with `$receiver` as every receiver's type. */
function fileResponseTrace(string $expression, string $receiver = BINARY_RESPONSE_FACTORY): callable
{
    return TraceScript::forChain($expression, $receiver);
}

/** The calls one expression recovers, straight out of the visitor. */
function recoveredCalls(string $expression, string $receiver = BINARY_RESPONSE_FACTORY): array
{
    $visitor = new FileResponseVisitor;
    fileResponseTrace($expression, $receiver)($visitor);

    return $visitor->calls;
}

it('names a media type for every extension it lists', function (string $extension, string $mediaType): void {
    expect(FileMediaTypes::forPath("exports/report.$extension"))->toBe($mediaType)
        ->and(FileMediaTypes::forPath('exports/report.'.strtoupper($extension)))->toBe($mediaType);
})->with(array_map(
    static fn (string $extension, string $mediaType): array => [$extension, $mediaType],
    array_keys(FileMediaTypes::BY_EXTENSION),
    array_values(FileMediaTypes::BY_EXTENSION),
));

it('leaves an extension it does not list unresolved', function (?string $path): void {
    // The unknown-entry contract: a path it cannot name leaves the media type to the octet-stream
    // fallback rather than guessing one from the name.
    expect(FileMediaTypes::forPath($path))->toBeNull();
})->with([
    'unknown extension' => ['exports/report.sqlite3'],
    'no extension' => ['exports/report'],
    'dotfile' => ['.env'],
    'nothing at all' => [null],
]);

it('reads a Content-Type header value as a bare media type', function (string $header, ?string $expected): void {
    expect(FileMediaTypes::normalize($header))->toBe($expected);
})->with([
    'plain' => ['text/csv', 'text/csv'],
    'cased' => ['Text/CSV', 'text/csv'],
    'parameterised' => ['text/csv; charset=utf-8', 'text/csv'],
    'padded' => ['  application/pdf  ', 'application/pdf'],
    'structured suffix' => ['application/vnd.api+json', 'application/vnd.api+json'],
    'not a media type' => ['csv', null],
    'empty' => ['', null],
]);

it('recognises the binary, streamed and streamed-JSON classes and nothing else', function (string $fqcn, string $expected): void {
    $recognised = match (true) {
        FrameworkClasses::isStreamedJson($fqcn) => 'streamedJson',
        FrameworkClasses::isBinaryFile($fqcn) => 'binary',
        FrameworkClasses::isStreamed($fqcn) => 'streamed',
        default => 'none',
    };

    expect($recognised)->toBe($expected);
})->with([
    'binary file' => [FrameworkClasses::BINARY_FILE_RESPONSE, 'binary'],
    'streamed' => [FrameworkClasses::STREAMED_RESPONSE, 'streamed'],
    // A subclass of the streamed response, so the order of the checks is what keeps it JSON.
    'streamed json' => [FrameworkClasses::STREAMED_JSON_RESPONSE, 'streamedJson'],
    'json response' => [FrameworkClasses::JSON_RESPONSE, 'none'],
    'redirect' => [FrameworkClasses::REDIRECT_RESPONSE, 'none'],
    'response base' => [FrameworkClasses::RESPONSE_BASE, 'none'],
    'not a class at all' => ['App\\Nope\\Missing', 'none'],
]);

it('reads what every recognised call proves', function (
    string $expression,
    string $receiver,
    string $class,
    string $mediaType,
    ?string $disposition,
    ?string $filename,
): void {
    $calls = recoveredCalls($expression, $receiver);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->responseClass)->toBe($class)
        ->and($calls[0]->mediaType)->toBe($mediaType)
        ->and($calls[0]->disposition)->toBe($disposition)
        ->and($calls[0]->filename)->toBe($filename);
})->with([
    // A download's media type comes from the FILE, which is what the server sniffs; the name it is
    // offered under comes from the name argument, and falls back to the file's own basename.
    'download' => ["response()->download('exports/invoices.pdf')", BINARY_RESPONSE_FACTORY, FrameworkClasses::BINARY_FILE_RESPONSE, 'application/pdf', 'attachment', 'invoices.pdf'],
    'download renamed' => ["response()->download('exports/2026-01.pdf', 'invoices.pdf')", BINARY_RESPONSE_FACTORY, FrameworkClasses::BINARY_FILE_RESPONSE, 'application/pdf', 'attachment', 'invoices.pdf'],
    'download displayed' => ["response()->download('exports/invoices.pdf', 'invoices.pdf', [], 'inline')", BINARY_RESPONSE_FACTORY, FrameworkClasses::BINARY_FILE_RESPONSE, 'application/pdf', 'inline', 'invoices.pdf'],
    // `file()` passes no disposition to BinaryFileResponse, so it really does set no header.
    'file' => ["response()->file('exports/invoices.pdf')", BINARY_RESPONSE_FACTORY, FrameworkClasses::BINARY_FILE_RESPONSE, 'application/pdf', null, 'invoices.pdf'],
    'file with a stated type' => ["response()->file('exports/invoices.bin', ['Content-Type' => 'application/pdf'])", BINARY_RESPONSE_FACTORY, FrameworkClasses::BINARY_FILE_RESPONSE, 'application/pdf', null, 'invoices.bin'],
    // A callback-written stream names nothing unless the headers do.
    'stream' => ["response()->stream(\$callback, 200, ['Content-Type' => 'text/csv'])", BINARY_RESPONSE_FACTORY, FrameworkClasses::STREAMED_RESPONSE, 'text/csv', null, null],
    'stream unstated' => ['response()->stream($callback)', BINARY_RESPONSE_FACTORY, FrameworkClasses::STREAMED_RESPONSE, BinaryRepresentation::ANY_MEDIA_TYPE, null, null],
    // The name is the download's, never the media type's: nothing sets a Content-Type here, so Symfony
    // labels the body `text/html` and `report.csv` would be a confident wrong answer.
    'streamDownload' => ["response()->streamDownload(\$callback, 'report.csv')", BINARY_RESPONSE_FACTORY, FrameworkClasses::STREAMED_RESPONSE, BinaryRepresentation::ANY_MEDIA_TYPE, 'attachment', 'report.csv'],
    'streamDownload typed' => ["response()->streamDownload(\$callback, 'report.csv', ['Content-Type' => 'text/csv'])", BINARY_RESPONSE_FACTORY, FrameworkClasses::STREAMED_RESPONSE, 'text/csv', 'attachment', 'report.csv'],
    // No name argument, so the framework sets no Content-Disposition either.
    'streamDownload unnamed' => ['response()->streamDownload($callback)', BINARY_RESPONSE_FACTORY, FrameworkClasses::STREAMED_RESPONSE, BinaryRepresentation::ANY_MEDIA_TYPE, null, null],
    // No name argument, and an explicit `null` reads the same way the framework's own is_null() does.
    'streamDownload null name' => ['response()->streamDownload($callback, null)', BINARY_RESPONSE_FACTORY, FrameworkClasses::STREAMED_RESPONSE, BinaryRepresentation::ANY_MEDIA_TYPE, null, null],
    'streamJson' => ['response()->streamJson($data)', BINARY_RESPONSE_FACTORY, FrameworkClasses::STREAMED_JSON_RESPONSE, 'application/json', null, null],
    // Symfony only labels a streamed JSON body when nothing else did, so a stated type still wins here —
    // unlike eventStream, whose own header the framework merges over the caller's.
    'streamJson typed' => ["response()->streamJson(\$data, 200, ['Content-Type' => 'application/problem+json'])", BINARY_RESPONSE_FACTORY, FrameworkClasses::STREAMED_JSON_RESPONSE, 'application/problem+json', null, null],
    // Laravel merges its own Content-Type over the caller's, so nothing at the call site can change it.
    'eventStream' => ['response()->eventStream($callback)', BINARY_RESPONSE_FACTORY, FrameworkClasses::STREAMED_RESPONSE, EventStreamRepresentation::MEDIA_TYPE, null, null],
    'eventStream overridden' => ["response()->eventStream(\$callback, ['Content-Type' => 'text/plain'])", BINARY_RESPONSE_FACTORY, FrameworkClasses::STREAMED_RESPONSE, EventStreamRepresentation::MEDIA_TYPE, null, null],
    // A disk hands back a STREAMED response, not a binary one, and always sets a disposition.
    'disk download' => ["\$disk->download('exports/invoices.pdf')", BINARY_RESPONSE_FILESYSTEM, FrameworkClasses::STREAMED_RESPONSE, 'application/pdf', 'attachment', 'invoices.pdf'],
    'disk response' => ["\$disk->response('exports/invoices.pdf')", BINARY_RESPONSE_FILESYSTEM, FrameworkClasses::STREAMED_RESPONSE, 'application/pdf', 'inline', 'invoices.pdf'],
    'disk response attached' => ["\$disk->response('exports/invoices.pdf', null, [], 'attachment')", BINARY_RESPONSE_FILESYSTEM, FrameworkClasses::STREAMED_RESPONSE, 'application/pdf', 'attachment', 'invoices.pdf'],
    // Both facades, which are static calls on a name rather than method calls on a type.
    'response facade' => ["\\Illuminate\\Support\\Facades\\Response::download('exports/invoices.pdf')", BINARY_RESPONSE_FACTORY, FrameworkClasses::BINARY_FILE_RESPONSE, 'application/pdf', 'attachment', 'invoices.pdf'],
    'storage facade' => ["\\Illuminate\\Support\\Facades\\Storage::download('exports/invoices.pdf')", BINARY_RESPONSE_FACTORY, FrameworkClasses::STREAMED_RESPONSE, 'application/pdf', 'attachment', 'invoices.pdf'],
    // A path helper wraps the literal in a call nothing can fold, and prepends only directories.
    'path helper' => ["response()->download(storage_path('app/exports/invoices.pdf'))", BINARY_RESPONSE_FACTORY, FrameworkClasses::BINARY_FILE_RESPONSE, 'application/pdf', 'attachment', 'invoices.pdf'],
]);

it('reads nothing off a call it cannot place', function (string $expression, string $receiver): void {
    expect(recoveredCalls($expression, $receiver))->toBe([]);
})->with([
    // The unknown-entry contract, on both axes: a method the table does not name, and an application's
    // own `download()` — which is why receivers are matched by TYPE and not by spelling.
    'unknown method' => ["response()->make('ok')", BINARY_RESPONSE_FACTORY],
    'unknown receiver' => ["\$reports->download('exports/invoices.pdf')", 'Workbench\\App\\Support\\ReportArchive'],
    'unknown facade' => ["\\Workbench\\App\\Facades\\Reports::download('exports/invoices.pdf')", BINARY_RESPONSE_FACTORY],
    // Nothing is read off a call whose arguments cannot be indexed by position.
    'named arguments' => ["response()->download(file: 'exports/invoices.pdf')", BINARY_RESPONSE_FACTORY],
    'first-class callable' => ['response()->download(...)', BINARY_RESPONSE_FACTORY],
]);

it('reads nothing off a spread it cannot read, on every call it knows', function (string $expression, string $receiver): void {
    // A spread the call site did not write out occupies no position: it fills its own and every later one
    // from a sequence, so the slots the reader indexes are not the values the call receives.
    // `download('exports/invoices.pdf', ...$args)` may well offer another name, and reading position 1
    // would publish `invoices.pdf` — a Content-Disposition the server never sends. One row per call the
    // table names, so the guard cannot go stale against it.
    expect(recoveredCalls($expression, $receiver))->toBe([]);
})->with([
    'download' => ["response()->download('exports/invoices.pdf', ...\$args)", BINARY_RESPONSE_FACTORY],
    'file' => ["response()->file('exports/invoices.pdf', ...\$args)", BINARY_RESPONSE_FACTORY],
    'stream' => ['response()->stream($callback, ...$args)', BINARY_RESPONSE_FACTORY],
    'streamDownload' => ['response()->streamDownload($callback, ...$args)', BINARY_RESPONSE_FACTORY],
    'streamJson' => ['response()->streamJson($data, ...$args)', BINARY_RESPONSE_FACTORY],
    'eventStream' => ['response()->eventStream(...$args)', BINARY_RESPONSE_FACTORY],
    'disk download' => ['$disk->download(...$args)', BINARY_RESPONSE_FILESYSTEM],
    'disk response' => ["\$disk->response('exports/invoices.pdf', null, [], ...\$args)", BINARY_RESPONSE_FILESYSTEM],
    // Both facades reach the same reader through a static call, so the guard has to hold on that path too.
    'response facade' => ["\\Illuminate\\Support\\Facades\\Response::download('exports/invoices.pdf', ...\$args)", BINARY_RESPONSE_FACTORY],
    'storage facade' => ['\\Illuminate\\Support\\Facades\\Storage::download(...$args)', BINARY_RESPONSE_FACTORY],
    // A name the reader cannot place is the other form that holds no position.
    'spread and name' => ["response()->download(...\$args, name: 'name.pdf')", BINARY_RESPONSE_FACTORY],
]);

it('reads a spread the call site wrote out, whose items ARE the arguments', function (): void {
    // Nothing is hidden in `...['name.pdf']`: the item sits at the position it takes, so the name the
    // server really sends is the one published — declining here would widen away a true filename.
    $calls = recoveredCalls("response()->download('exports/invoices.pdf', ...['name.pdf'])");

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->filename)->toBe('name.pdf')
        ->and($calls[0]->disposition)->toBe('attachment');
});

it('widens rather than reads through a spread inside a path helper', function (): void {
    // The helper's own argument is a separate read, and a spread there folds to an array rather than a
    // string — so the path is simply unknown. Octet-stream and no name is what the call still proves.
    $calls = recoveredCalls("response()->download(storage_path(...['app/exports/invoices.pdf']))");

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->mediaType)->toBe(BinaryRepresentation::OCTET_STREAM)
        ->and($calls[0]->filename)->toBeNull()
        ->and($calls[0]->disposition)->toBe('attachment');
});

it('falls back to what the response class alone proves', function (string $fqcn, string $mediaType, array $schema, bool $diagnosed): void {
    // No call was reached, so only the class speaks. A file body gets the octet-stream the server itself
    // falls back to; a callback-written stream gets no media type at all, which is the one case left
    // where the author can still do something.
    [$responses, $document, $diagnostics] = documentForReturn(new ClassT($fqcn));

    $body = $responses['200']['content'][$mediaType]['schema'];
    unset($body['x-docuccino']);

    expect(array_keys($responses['200']['content']))->toBe([$mediaType])
        ->and($body)->toBe($schema)
        ->and($responses['200'])->not->toHaveKey('headers')
        ->and(typeSchemas($document))->toBe([])
        ->and(in_array('inferred-response.payload-unrecoverable', array_map(static fn ($d): string => $d->code, $diagnostics), true))
        ->toBe($diagnosed);
})->with([
    'binary file' => [FrameworkClasses::BINARY_FILE_RESPONSE, BinaryRepresentation::OCTET_STREAM, BinaryRepresentation::SCHEMA, false],
    'streamed' => [FrameworkClasses::STREAMED_RESPONSE, BinaryRepresentation::ANY_MEDIA_TYPE, BinaryRepresentation::SCHEMA, true],
    'streamed json' => [FrameworkClasses::STREAMED_JSON_RESPONSE, 'application/json', [], true],
]);

it('documents a download as its own media type, with the disposition the call sets', function (): void {
    [$responses, $document, $diagnostics] = documentForReturn(
        new ClassT(FrameworkClasses::BINARY_FILE_RESPONSE),
        trace: fileResponseTrace("response()->download('exports/invoices.pdf')"),
    );

    $schema = $responses['200']['content']['application/pdf']['schema'];
    unset($schema['x-docuccino']);

    expect(array_keys($responses['200']['content']))->toBe(['application/pdf'])
        ->and($schema)->toBe(['type' => 'string', 'format' => 'binary'])
        ->and($responses['200']['headers']['Content-Disposition'])->toBe([
            'description' => 'Asks the client to save the body as a file rather than display it.',
            'schema' => ['type' => 'string'],
            'example' => 'attachment; filename="invoices.pdf"',
        ])
        ->and(typeSchemas($document))->toBe([])
        // Nothing is left to recover: the body, its media type and the disposition are all documented.
        ->and(array_map(static fn ($d): string => $d->code, $diagnostics))
        ->not->toContain('inferred-response.payload-unrecoverable');
});

it('documents an inline file with no Content-Disposition, because the call sets none', function (): void {
    // `file()` hands BinaryFileResponse no disposition, so the header is genuinely absent — documenting
    // `inline` would be a header the response never sends.
    [$responses] = documentForReturn(
        new ClassT(FrameworkClasses::BINARY_FILE_RESPONSE),
        trace: fileResponseTrace("response()->file('exports/invoices.pdf')"),
    );

    expect(array_keys($responses['200']['content']))->toBe(['application/pdf'])
        ->and($responses['200'])->not->toHaveKey('headers');
});

it('documents server-sent events as the wire format, never as one event', function (): void {
    // An SSE body is a sequence of frames with no end; a schema naming the yielded object would tell a
    // consumer the body IS one event, which is false for every stream that sends two.
    [$responses, , $diagnostics] = documentForReturn(
        new ClassT(FrameworkClasses::STREAMED_RESPONSE),
        trace: fileResponseTrace('response()->eventStream($callback)'),
    );

    $schema = $responses['200']['content'][EventStreamRepresentation::MEDIA_TYPE]['schema'];
    unset($schema['x-docuccino']);

    expect(array_keys($responses['200']['content']))->toBe([EventStreamRepresentation::MEDIA_TYPE])
        ->and($schema)->toBe(['type' => 'string'])
        ->and($schema)->not->toHaveKey('properties')
        ->and($responses['200'])->not->toHaveKey('headers')
        ->and(array_map(static fn ($d): string => $d->code, $diagnostics))
        ->not->toContain('inferred-response.payload-unrecoverable');
});

it('says the media type is missing when a stream never states one', function (): void {
    // The one hit left where the reader can act, and it names a real defect: with no Content-Type the
    // framework labels the download `text/html`.
    [$responses, , $diagnostics] = documentForReturn(
        new ClassT(FrameworkClasses::STREAMED_RESPONSE),
        trace: fileResponseTrace("response()->streamDownload(\$callback, 'report.csv')"),
    );

    $raised = array_values(array_filter(
        $diagnostics,
        static fn ($d): bool => $d->code === 'inferred-response.payload-unrecoverable' && $d->routeSignature === 'GET /api/forms',
    ));

    expect(array_keys($responses['200']['content']))->toBe([BinaryRepresentation::ANY_MEDIA_TYPE])
        ->and($responses['200']['headers']['Content-Disposition']['example'])->toBe('attachment; filename="report.csv"')
        ->and($raised)->toHaveCount(1)
        ->and($raised[0]->severity->value)->toBe('info')
        ->and($raised[0]->message)->toContain('media type')
        ->and($raised[0]->help)->toContain("#[Response(mediaType: 'text/csv')]");
});

it('gives an action that serves two media types one content entry each', function (): void {
    // Both calls answer the same 200, and folding them into one entry would file one body under a media
    // type the other contradicts. Sorted, so an added return path never reshuffles the others.
    [$responses] = documentForReturn(
        new ClassT(FrameworkClasses::BINARY_FILE_RESPONSE),
        trace: fileResponseTrace("[\$factory->download('exports/invoices.pdf'), \$factory->download('exports/summary.csv')]"),
    );

    expect(array_keys($responses['200']['content']))->toBe(['application/pdf', 'text/csv']);
});

it('documents no disposition when the calls disagree about it', function (): void {
    // One path attaches and the other displays, so the header is set on one of them — documenting either
    // would be a claim about the wrong path.
    [$responses] = documentForReturn(
        new ClassT(FrameworkClasses::BINARY_FILE_RESPONSE),
        trace: fileResponseTrace("[\$factory->download('exports/invoices.pdf'), \$factory->file('exports/invoices.pdf')]"),
    );

    expect($responses['200'])->not->toHaveKey('headers');
});

it('drops the filename example when the calls offer different names', function (): void {
    // The disposition still holds — both attach — but no single name does, so the example goes rather
    // than naming one of the two files.
    [$responses] = documentForReturn(
        new ClassT(FrameworkClasses::BINARY_FILE_RESPONSE),
        trace: fileResponseTrace("[\$factory->download('exports/invoices.pdf'), \$factory->download('exports/summary.pdf')]"),
    );

    expect($responses['200']['headers']['Content-Disposition'])->toBe([
        'description' => 'Asks the client to save the body as a file rather than display it.',
        'schema' => ['type' => 'string'],
    ]);
});

it('carries a binary body through validation and every OpenAPI version', function (): void {
    [, , $diagnostics, $result] = documentForReturn(
        new ClassT(FrameworkClasses::BINARY_FILE_RESPONSE),
        trace: fileResponseTrace("response()->download('exports/invoices.pdf')"),
    );

    expect(array_map(static fn ($d): string => $d->code, $diagnostics))->not->toContain('document.schema-invalid');

    foreach ([new OpenApi32Emitter, new OpenApi31DownlevelEmitter, new OpenApi30DownlevelEmitter] as $emitter) {
        expect($emitter->emit($result->document))->toContain('"application/pdf"')
            ->and($emitter->emit($result->document))->toContain('"binary"');
    }
});

it('carries the any-media-type range through validation and every OpenAPI version', function (): void {
    // A media-type RANGE is a legal content key in 3.0, 3.1 and 3.2 alike, so the honest degradation must
    // not arrive as a validation failure instead.
    [, , $diagnostics, $result] = documentForReturn(new ClassT(FrameworkClasses::STREAMED_RESPONSE));

    expect(array_map(static fn ($d): string => $d->code, $diagnostics))->not->toContain('document.schema-invalid');

    foreach ([new OpenApi32Emitter, new OpenApi31DownlevelEmitter, new OpenApi30DownlevelEmitter] as $emitter) {
        expect($emitter->emit($result->document))->toContain('"*/*"');
    }
});

it('documents a workbench controller that downloads a file', function (): void {
    // The whole pipeline over a real controller, with the call the trace reaches deciding the media type
    // and the disposition that the return type alone could never have said.
    app('router')->get('api/invoices/export', [DownloadController::class, 'invoice']);
    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [
            DownloadController::class.'::invoice' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT(FrameworkClasses::BINARY_FILE_RESPONSE), new SourceLocation(''))],
            ),
        ],
        traceOverrides: [
            DownloadController::class.'::invoice' => fileResponseTrace("response()->download(storage_path('app/exports/invoices.pdf'), 'invoices.pdf')"),
        ],
    ));

    $responses = generateDocument()->document->toArray()['paths']['/api/invoices/export']['get']['responses'];
    $schema = $responses['200']['content']['application/pdf']['schema'];
    unset($schema['x-docuccino']);

    expect($schema)->toBe(['type' => 'string', 'format' => 'binary'])
        ->and($responses['200']['description'])->toBe('OK')
        ->and($responses['200']['headers']['Content-Disposition']['example'])->toBe('attachment; filename="invoices.pdf"');
});
