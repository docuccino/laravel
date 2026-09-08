<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\Frame;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\Http\Controllers\FormController;

/**
 * The question the report arrived as: an operation carries a 500 that stands in for a status nothing
 * read, and `docuccino:explain` named the action's own line and stopped there — exactly where the
 * reader's question starts.
 *
 * The trail cannot answer the rest of it. A contribution records the producer, the rung, the value and
 * one source; it has no room for which exception, or for which fold gave up, and inventing a field for
 * either would put engine vocabulary into a document surface. What DOES carry all three is the notice
 * the build raises about the same throw — so the command prints what the build reported about the
 * operation under its trail, and the two halves meet on one screen.
 */
function unplacedStatusEngine(): TypeEngine
{
    $throw = new ThrownException(
        exceptionFqcn: 'Workbench\\App\\Exceptions\\ExportConflictException',
        httpStatusHint: null,
        callChain: [new Frame(
            'FormController::index',
            new SourceLocation('app/Http/Controllers/FormController.php', 31),
        )],
        confidence: ThrowConfidence::Certain,
        disposition: ThrowDisposition::Signal,
    );

    // The notice as the analyser raises it: the exception, the site the `throw` is written at — a call
    // away from the action, which is the part the trail never had — and which fold gave up.
    $notice = new Diagnostic(
        severity: Severity::Info,
        code: 'inference.http-exception-status-unread',
        message: 'Workbench\\App\\Exceptions\\ExportConflictException is thrown at app/Services/ExportProbeQuery.php:22 with no status this build could read: the construction the throw names does not fold to one status.',
        help: 'Say the status as a constant where the exception is built.',
    );

    return WorkbenchEngine::make(analysisOverrides: [
        FormController::class.'::index' => new ActionAnalysis(throws: [$throw], diagnostics: [$notice]),
    ]);
}

beforeEach(function (): void {
    app()->instance(TypeEngine::class, unplacedStatusEngine());
});

it('names the throw behind an unplaced status where the trail stops', function (): void {
    $exit = Artisan::call('docuccino:explain', [
        'route' => 'GET /api/forms',
        'document' => 'default',
        '--field' => 'responses.500.description',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        // The trail's half: which rung published the response.
        ->and($output)->toContain('fallback')
        // And the half it has nowhere to put — which exception, thrown where, which fold gave up.
        ->and($output)->toContain('inference.http-exception-status-unread')
        ->and($output)->toContain('ExportConflictException')
        ->and($output)->toContain('app/Services/ExportProbeQuery.php:22')
        ->and($output)->toContain('does not fold to one status')
        ->and($output)->toContain('Say the status as a constant where the exception is built.');
});

it('carries the same answer to a tool reading the trail as JSON', function (): void {
    Artisan::call('docuccino:explain', [
        'route' => 'GET /api/forms',
        'document' => 'default',
        '--json' => true,
    ]);

    /** @var array{diagnostics: list<array<string, mixed>>} $payload */
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['diagnostics'])->toHaveCount(1)
        ->and($payload['diagnostics'][0]['code'])->toBe('inference.http-exception-status-unread')
        ->and($payload['diagnostics'][0]['message'])->toContain('app/Services/ExportProbeQuery.php:22')
        // Tagged with the route it belongs to, which is what made the match exact rather than fuzzy.
        ->and($payload['diagnostics'][0]['routeSignature'])->toBe('GET /api/forms');
});

/**
 * The filter is the point of the join: a reader explaining one operation is shown what the build said
 * about THAT one, and `docuccino:generate` remains the place the whole document's report is read.
 */
it('says nothing about an operation the build reported nothing for', function (): void {
    Artisan::call('docuccino:explain', [
        'route' => 'GET /api/ping',
        'document' => 'default',
        '--json' => true,
    ]);

    /** @var array{diagnostics: list<array<string, mixed>>} $payload */
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['diagnostics'])->toBe([]);
});
