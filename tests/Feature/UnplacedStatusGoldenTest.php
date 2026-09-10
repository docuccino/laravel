<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\Frame;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Exceptions\LedgerRejectedException;
use Workbench\App\Http\Controllers\LedgerController;
use Workbench\App\Queries\LedgerReviewQuery;

/**
 * The three ways an error response can be keyed, in one byte-locked document, because until now no
 * golden published a 500 at all and every claim about this population was a claim about nothing.
 *
 * A 500 is not one answer. Two of these are readings the document is right to publish — a status the
 * code states, and an exception that is no HTTP error, which the framework really does answer 500 for —
 * and one is a STAND-IN, a key the document cannot do without because nothing read a number. A reader
 * cannot tell them apart from the status, so the response that stands in says so
 * ({@see ResponseDraft::STATUS_UNPLACED}) and the other two do not: a build gate asking "does this
 * document publish a status nothing read" is then one member away, and a reader is one line away from
 * knowing whether the 500 in front of them is a claim or a placeholder.
 *
 * The trail carries the other half. A response the build produced from a throw names the exception and
 * the line the `throw` is written at — the deepest frame of its call chain, the same site the engine's
 * notice names — rather than the action, which the operation already identifies. That is what turned a
 * dead end into an answer: the reader who has to change something needs a class name, and the status
 * alone gives them none.
 */
function ledgerFile(string $class): string
{
    return (string) (new ReflectionClass($class))->getFileName();
}

/**
 * The line one throw is written at, read off the file rather than pinned here — and asserted to be the
 * line it claims, so a fixture that drifts fails loudly instead of pinning a line at random.
 */
function ledgerThrowLine(string $class, string $needle): int
{
    $lines = explode("\n", (string) file_get_contents(ledgerFile($class)));

    foreach ($lines as $index => $line) {
        if (str_contains($line, $needle)) {
            return $index + 1;
        }
    }

    expect($needle)->toBe('a line of '.$class);

    return 0;
}

/** @return callable(): TypeEngine */
function ledgerEngine(): callable
{
    $body = new ArrayShapeT([
        new ArrayShapeField('ledger', ScalarT::string()),
        new ArrayShapeField('entries', ScalarT::int()),
    ]);
    $at = new SourceLocation('');

    // The chain the real engine hands back for a throw a controller reaches through a collaborator:
    // the action first, the site the `throw` is written at last.
    $chain = [
        new Frame('LedgerController::review', new SourceLocation(
            ledgerFile(LedgerController::class),
            ledgerThrowLine(LedgerController::class, 'public function review('),
        )),
        new Frame('LedgerReviewQuery::review', new SourceLocation(
            ledgerFile(LedgerReviewQuery::class),
            ledgerThrowLine(LedgerReviewQuery::class, 'throw $rejected;'),
        )),
    ];

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: [
        // A rethrow of a class whose factories answer two statuses: nothing on the path built the
        // exception and the class agrees on no number, so the hint is null — and the notice the engine
        // raises about it travels with the analysis.
        LedgerController::class.'::review' => new ActionAnalysis(
            returns: [new ReturnSite($body, $at)],
            throws: [new ThrownException(
                LedgerRejectedException::class,
                null,
                $chain,
                ThrowConfidence::Declared,
                ThrowDisposition::Signal,
            )],
            diagnostics: [new Diagnostic(
                severity: Severity::Info,
                code: 'inference.http-exception-status-unread',
                message: LedgerRejectedException::class.' is thrown at '
                    .'workbench/app/Queries/LedgerReviewQuery.php:'
                    .ledgerThrowLine(LedgerReviewQuery::class, 'throw $rejected;')
                    .' with no status this build could read: neither the throw nor the code that declared it '
                    .'builds the exception, and the class states no single status of its own.',
                help: 'Build the exception somewhere this throw can be read from.',
            )],
        ),
        // The same class, thrown through a factory that names its status: read, so 423 and no stand-in.
        LedgerController::class.'::post' => new ActionAnalysis(
            returns: [new ReturnSite($body, $at)],
            throws: [new ThrownException(
                LedgerRejectedException::class,
                423,
                [new Frame('LedgerController::post', new SourceLocation(
                    ledgerFile(LedgerController::class),
                    ledgerThrowLine(LedgerController::class, 'throw LedgerRejectedException::locked'),
                ))],
                ThrowConfidence::Certain,
                ThrowDisposition::Signal,
            )],
        ),
        // No HTTP error at all. The framework answers 500 for one of these, so the 500 is a reading and
        // the response says nothing about standing in for anything.
        LedgerController::class.'::reconcile' => new ActionAnalysis(
            returns: [new ReturnSite($body, $at)],
            throws: [new ThrownException(
                'RuntimeException',
                500,
                [new Frame('LedgerController::reconcile', new SourceLocation(
                    ledgerFile(LedgerController::class),
                    ledgerThrowLine(LedgerController::class, 'throw new RuntimeException'),
                ))],
                ThrowConfidence::Certain,
                ThrowDisposition::Signal,
            )],
        ),
    ]);
}

function ledgerRoutes(): callable
{
    return static function (Router $router): void {
        $router->get('api/ledgers/{ledger}/review', [LedgerController::class, 'review']);
        $router->post('api/ledgers/{ledger}/post', [LedgerController::class, 'post']);
        $router->post('api/ledgers/{ledger}/reconcile', [LedgerController::class, 'reconcile']);
    };
}

it('tells a status it stood in for from one it read, byte-identically', function (): void {
    // Warm as well as cold: the notice explaining the stand-in rides the operation fragment, and a warm
    // build that replayed the bytes and lost it would leave the placeholder unexplained again — which is
    // the whole defect, arriving by another door.
    $warm = assertWarmEqualsCold(ledgerRoutes(), ledgerRoutes(), ledgerEngine());

    assertGolden('workbench-unplaced-status.uir.json', (new UirEmitter)->emit($warm->document));

    expect(diagnosticsCoded($warm->diagnostics, 'inference.http-exception-status-unread'))->toHaveCount(1);
});

/**
 * The fact read back off the finished document, keyed the way a build gate would key it: exactly the
 * responses whose status nothing read, and no others. Asserted beside the golden rather than left to
 * it, because a golden fails on any byte and says nothing about which distinction moved.
 */
it('marks the stand-in and only the stand-in', function (): void {
    $document = emittedArray(localityBuild(ledgerRoutes(), ledgerEngine()));

    $marked = [];
    $statuses = [];

    /** @var array<string, array<string, mixed>> $paths */
    $paths = $document['paths'];
    foreach ($paths as $path => $item) {
        foreach ($item as $method => $operation) {
            if (! is_array($operation) || ! is_array($operation['responses'] ?? null)) {
                continue;
            }

            /** @var array<string, array<string, mixed>> $responses */
            $responses = $operation['responses'];
            foreach ($responses as $status => $response) {
                $statuses[] = $path.' '.$method.' '.$status;

                $extension = $response['x-docuccino'] ?? null;
                $facts = is_array($extension) && is_array($extension['facts'] ?? null) ? $extension['facts'] : [];

                if (($facts[ResponseDraft::STATUS_UNPLACED] ?? null) === true) {
                    $marked[] = $path.' '.$method.' '.$status;
                }
            }
        }
    }

    sort($marked);

    // A document with no responses would satisfy the comparison below without proving anything, and
    // both 500s have to be in the corpus or the distinction is untested.
    expect($statuses)->toContain('/api/ledgers/{ledger}/review get 500')
        ->and($statuses)->toContain('/api/ledgers/{ledger}/reconcile post 500')
        ->and($statuses)->toContain('/api/ledgers/{ledger}/post post 423')
        ->and($marked)->toBe(['/api/ledgers/{ledger}/review get 500']);
});

/**
 * The trail's half, held to the contract rather than to what the code answers: a response the build
 * produced from a throw names the EXCEPTION and the site the `throw` is written at. The site is the
 * deepest frame, which is the line the engine's own notice names — the two are documented to agree, and
 * for as long as they did not, a reader was sent to the action and told nothing about which class to
 * look at.
 */
it('names the exception and the throw site in the trail, not the action', function (): void {
    $document = emittedArray(localityBuild(ledgerRoutes(), ledgerEngine()));

    /** @var array<string, mixed> $response */
    $response = $document['paths']['/api/ledgers/{ledger}/review']['get']['responses']['500'];
    /** @var array{provenance: list<array<string, mixed>>} $extension */
    $extension = $response['x-docuccino'];

    $sources = [];
    foreach ($extension['provenance'] as $record) {
        /** @var array{file: string, line?: int, symbol?: string}|null $source */
        $source = is_array($record['source'] ?? null) ? $record['source'] : null;
        if ($source !== null) {
            $sources[] = ($source['symbol'] ?? '').' @ '.$source['file'].':'.($source['line'] ?? 0);
        }
    }

    $line = ledgerThrowLine(LedgerReviewQuery::class, 'throw $rejected;');

    expect($sources)->not->toBeEmpty()
        ->and($sources)->toBe(array_fill(
            0,
            count($sources),
            LedgerRejectedException::class.' @ workbench/app/Queries/LedgerReviewQuery.php:'.$line,
        ));
});
