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
 *
 * The log is fed by the pipeline draining each route's notes, so `collect()` is called once per route that
 * deferred — repeats across routes are the normal case, not an edge one.
 */
it('collects deferrals per callback, deduping repeated exception types', function (): void {
    $log = new HandlerDeferralLog;
    $log->collect('App\\Exceptions\\Renderer::__invoke', ['A', 'B']);
    $log->collect('App\\Exceptions\\Renderer::__invoke', ['A']); // a second route through it — deduped

    $summaries = $log->summaries();

    expect($summaries)->toHaveCount(1)
        ->and($summaries[0]['callback'])->toBe('App\\Exceptions\\Renderer::__invoke')
        ->and($summaries[0]['exceptions'])->toBe(['A', 'B']);
});

it('names the exception types in sorted order, not in the order the routes were met', function (): void {
    // Two apps differing only in which route sorts first must publish the same line: the summary is a
    // function of what could not be folded, never of the order it was met.
    $forward = new HandlerDeferralLog;
    $forward->collect('App\\Renderer::__invoke', ['Zeta']);
    $forward->collect('App\\Renderer::__invoke', ['Alpha']);

    $backward = new HandlerDeferralLog;
    $backward->collect('App\\Renderer::__invoke', ['Alpha']);
    $backward->collect('App\\Renderer::__invoke', ['Zeta']);

    expect($forward->summaries())->toBe($backward->summaries())
        ->and($forward->summaries()[0]['exceptions'])->toBe(['Alpha', 'Zeta']);
});

it('empties the aggregate on forget, so one document never reports another’s deferrals', function (): void {
    // The pipeline forgets before a document's first route; a container-scoped log outlives a build, so
    // without this an export of several documents would repeat the first one's findings under every key.
    $log = new HandlerDeferralLog;
    $log->collect('App\\Renderer::__invoke', ['A']);
    $log->forget();

    expect($log->summaries())->toBe([]);
});

it('emits one summary diagnostic per callback with count + first few exception types', function (): void {
    $log = new HandlerDeferralLog;
    $log->collect('App\\Renderer::__invoke', ['E1', 'E2', 'E3', 'E4', 'E5']);

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
    $log->collect('B\\Renderer::__invoke', ['X']);
    $log->collect('A\\Renderer::render', ['Y']);

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

it('names the channel the tier records its notes on', function (): void {
    // The channel is how the pipeline routes a fragment's notes here; a mismatch is a silently empty log.
    expect((new HandlerDeferralLog)->channel())->toBe(HandlerDeferralLog::CHANNEL);
});
