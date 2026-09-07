<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * The engine's per-action memo on the axis it acts on: the published DOCUMENT.
 *
 * Every other row about the memo compares one `ActionAnalysis` against another inside the runner
 * subprocess, which measures the mechanism rather than the product. The claim the memo makes is that N
 * documents built in ONE process publish what N built in separate processes publish — a whole export
 * run asks the identical `ActionRef` sequence once per version document — and a document is what a
 * consumer reads, what a golden pins and what a generated client is compiled from.
 *
 * So the analyses come from the real engine under both provenances: one engine asked the identical ref
 * twice, and two processes with an engine each asked once. Each of the four is driven through the WHOLE
 * pipeline, and the documents are compared as bytes AND as diagnostics — a diagnostic raised while
 * computing the first answer and dropped from the second is the failure mode this exists for, and
 * fewer diagnostics is a silent degradation rather than a saving.
 *
 * The action is one whose analysis is worth carrying: `abortAction` recovers two thrown statuses with
 * absolute fixture-app paths in their call chains, so the comparison covers what the adapter makes of
 * a path as well as what it makes of a shape.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * The document the pipeline publishes for one recovered analysis, and the diagnostics it raised.
 *
 * @return array{0: array<string, mixed>, 1: list<string>}
 */
function memoDocument(ActionAnalysis $analysis): array
{
    app()->instance(TypeEngine::class, new StubTypeEngine(
        analyses: ['Workbench\\App\\Http\\Controllers\\FormController::index' => $analysis],
    ));

    $result = generateDocument();

    return [$result->document->toArray(), array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code."\0".$diagnostic->message."\0".(string) $diagnostic->help,
        $result->diagnostics,
    )];
}

it('publishes the same bytes and the same diagnostics for one engine\'s repeated ask as for a fresh engine\'s', function (): void {
    $action = ['app/Http/Controllers/ThrowsController.php', 'App\\Http\\Controllers\\ThrowsController', 'abortAction'];

    // Two processes, an engine each, one ask each — the baseline the memo must not move.
    $ownProcess = ActionAnalysis::fromArray(FixtureRunner::analyze(...$action));
    $ownProcessAgain = ActionAnalysis::fromArray(FixtureRunner::analyze(...$action));

    // One engine, the identical ref asked twice with another action between — the shape of an export
    // run, where the second document's ask is served from the memo.
    $repeated = FixtureRunner::analyzeRepeat(...$action, otherMethod: 'namedAbortAction');

    /** @var array<string, mixed> $first */
    $first = $repeated['first'];
    /** @var array<string, mixed> $second */
    $second = $repeated['second'];

    [$coldDocument, $coldDiagnostics] = memoDocument($ownProcess);
    [$coldAgainDocument, $coldAgainDiagnostics] = memoDocument($ownProcessAgain);
    [$firstDocument, $firstDiagnostics] = memoDocument(ActionAnalysis::fromArray($first));
    [$secondDocument, $secondDiagnostics] = memoDocument(ActionAnalysis::fromArray($second));

    // Equal-and-both-empty would prove nothing, so what is being compared is stated first: the
    // analysis really reached the document, and the document really raised something.
    expect($coldDocument['paths']['/api/forms']['get']['responses'])->toHaveKeys(['403', '404'])
        ->and($coldDiagnostics)->not->toBe([])
        // Two processes agree, which is what makes the memo the only variable below.
        ->and(json_encode($coldAgainDocument))->toBe(json_encode($coldDocument))
        ->and($coldAgainDiagnostics)->toBe($coldDiagnostics)
        // And one engine's two answers publish that same document, bytes and diagnostics both.
        ->and(json_encode($firstDocument))->toBe(json_encode($coldDocument))
        ->and(json_encode($secondDocument))->toBe(json_encode($coldDocument))
        ->and($firstDiagnostics)->toBe($coldDiagnostics)
        ->and($secondDiagnostics)->toBe($coldDiagnostics);
})->group('fixture');
