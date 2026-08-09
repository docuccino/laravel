<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\DiagnosticCollector;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerDeferralLog;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerDeferralSummaryTransformer;

/**
 * Deferral dedupe (design §6): the tier used to emit one `too-dynamic` diagnostic per (route × thrown
 * type) — 656 near-identical lines when run against a large production Laravel app. Now each defer is collected per CALLBACK and the
 * transformer emits one summary naming the count + first few exception types.
 */
it('collects deferrals per callback, deduping repeated exception types', function (): void {
    $log = new HandlerDeferralLog;
    $log->record('App\\Exceptions\\Renderer::__invoke', 'A');
    $log->record('App\\Exceptions\\Renderer::__invoke', 'B');
    $log->record('App\\Exceptions\\Renderer::__invoke', 'A'); // repeat — deduped

    $summaries = $log->summaries();

    expect($summaries)->toHaveCount(1)
        ->and($summaries[0]['callback'])->toBe('App\\Exceptions\\Renderer::__invoke')
        ->and($summaries[0]['exceptions'])->toBe(['A', 'B']);
});

it('emits one summary diagnostic per callback with count + first few exception types', function (): void {
    $log = new HandlerDeferralLog;
    foreach (['E1', 'E2', 'E3', 'E4', 'E5'] as $exception) {
        $log->record('App\\Renderer::__invoke', $exception);
    }

    $collector = new DiagnosticCollector;
    $context = new DocumentContext(new DocumentConfig(key: 'd', info: ['title' => 'T', 'version' => '1']), 'doc:d', $collector);
    (new HandlerDeferralSummaryTransformer($log))->transform(new UirDocumentDraft([]), $context);

    $diagnostics = $collector->all();
    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('inferred-handler.too-dynamic')
        ->and($diagnostics[0]->message)->toContain('5 exception type(s)')
        ->and($diagnostics[0]->message)->toContain('E1, E2, E3')
        ->and($diagnostics[0]->message)->toContain('(and 2 more)')
        ->and($diagnostics[0]->message)->toContain('App\\Renderer::__invoke');
});

it('emits one diagnostic per distinct callback, in sorted order', function (): void {
    $log = new HandlerDeferralLog;
    $log->record('B\\Renderer::__invoke', 'X');
    $log->record('A\\Renderer::render', 'Y');

    $collector = new DiagnosticCollector;
    $context = new DocumentContext(new DocumentConfig(key: 'd', info: ['title' => 'T', 'version' => '1']), 'doc:d', $collector);
    (new HandlerDeferralSummaryTransformer($log))->transform(new UirDocumentDraft([]), $context);

    $messages = array_map(static fn ($d): string => $d->message, $collector->all());
    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toContain('A\\Renderer::render')
        ->and($messages[1])->toContain('B\\Renderer::__invoke');
});

it('produces no diagnostics when nothing deferred', function (): void {
    $collector = new DiagnosticCollector;
    $context = new DocumentContext(new DocumentConfig(key: 'd', info: ['title' => 'T', 'version' => '1']), 'doc:d', $collector);
    (new HandlerDeferralSummaryTransformer(new HandlerDeferralLog))->transform(new UirDocumentDraft([]), $context);

    expect($collector->all())->toBe([]);
});
