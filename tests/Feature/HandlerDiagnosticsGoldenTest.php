<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerDeferralLog;
use Docuccino\Laravel\Tests\Fixtures\InferredHandler\DeferralCarrierController;
use Docuccino\Laravel\Tests\Fixtures\InferredHandler\PortableCallbackLabels;
use Docuccino\Laravel\Tests\Support\RouteNoteRecorder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Routing\Router;

/**
 * The two diagnostics that name a callback nothing but a file can name — a skipped render callback and a
 * handler that could not fold a response — locked in emitted bytes, because a machine path here is a
 * machine path in the artifact (`x-docuccino.diagnostics`, which is what `--embed-diagnostics` writes).
 * A golden is the only thing that fails on the WHOLE string rather than on the part an assertion thought
 * to look at, so it is what catches the path coming back somewhere else in the sentence.
 *
 * It embeds only these two codes, so no unrelated build warning churns it. It carries exactly ONE route,
 * and it has to: a deferral is a thing a ROUTE discovered, so its label reaches the summary as a note on
 * that route's fragment, and with no routes there is nothing to carry it. (The skip is still a per-build
 * diagnostic that needs none.) The carrier cannot be left out of the bytes selectively, since `contentHash`
 * is a hash of the whole document — so it is {@see DeferralCarrierController}, which lives in a file of its
 * own for the reason `PortableCallbackLabels` does: it publishes as little as an operation can, and the one
 * line its provenance names moves only when that file is edited.
 */
it('emits the callback diagnostics byte-identical to their committed golden', function (): void {
    /** @var object $handler */
    $handler = app(ExceptionHandler::class);
    $handler->renderable(PortableCallbackLabels::unanalysable());
    Docuccino::extend(new RouteNoteRecorder(HandlerDeferralLog::CHANNEL, PortableCallbackLabels::deferralLabel(), RuntimeException::class));

    $result = localityBuild(static fn (Router $router) => $router->get('api/zz-deferring', [DeferralCarrierController::class, 'index']));

    $embedded = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $d): bool => str_starts_with($d->code, 'inferred-handler.'),
    ));

    $document = $result->document->toArray();
    $document['x-docuccino']['diagnostics'] = array_map(static fn (Diagnostic $d): array => $d->toArray(), $embedded);

    $emitted = (new UirEmitter)->emit(UirDocument::fromArray($document));

    assertGolden('handler-diagnostics.uir.json', $emitted);

    // What the golden is for, said out loud: neither the app's base path nor the checkout above it
    // may appear anywhere in those bytes, and both labels still name a file and a line.
    expect($emitted)->not->toContain(base_path())
        ->and($emitted)->not->toContain(dirname(__DIR__, 4))
        ->and($emitted)->toContain('tests/Fixtures/InferredHandler/PortableCallbackLabels.php');
});
