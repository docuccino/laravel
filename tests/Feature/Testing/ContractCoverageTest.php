<?php

declare(strict_types=1);

use Docuccino\Core\Contract\CheckResult;
use Docuccino\Core\Contract\Coverage\CoverageLog;
use Docuccino\Core\Contract\Coverage\CoverageMerge;
use Docuccino\Core\Contract\Coverage\CoverageReport;
use Docuccino\Core\Contract\Outcome;
use Docuccino\Core\Contract\Violation;
use Docuccino\Laravel\Testing\ApiContract;
use Docuccino\Laravel\Testing\CoverageRecorder;
use Docuccino\Laravel\Testing\ObservedExchange;
use Docuccino\Laravel\Testing\ParallelRun;
use PHPUnit\Framework\AssertionFailedError;

/*
 * Contract coverage: what a process records, what it writes down, and — the whole point of writing it
 * down — that N workers' logs merge to exactly what one process would have said. What gets recorded is
 * the documented RESPONSE, because that is what the contract promises a client.
 */
beforeEach(function (): void {
    $this->root = coverageFixtureDir('recorder');
    $this->contract = workbenchContract();
});

afterEach(function (): void {
    removeCoverageFixture($this->root);
    @unlink($this->contract);
    ApiContract::reset();
});

it('records the responses the run exercised, by operation id and status', function (): void {
    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));

    $exercised = ApiContract::coverage()->exercised();

    expect($exercised)->toHaveCount(1)
        ->and($exercised[0])->toEndWith('@200');

    $parsed = CoverageLog::parse($exercised[0]);

    expect(ApiContract::index()->operation((string) $parsed['id'])?->label())->toBe('GET /api/forms');
});

it('records a response once however many times the suite hits it', function (): void {
    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));
    ApiContract::assertions()->assertValidExchange(contractResponse('GET', '/api/forms', body: '[]'));

    expect(ApiContract::coverage()->exercised())->toHaveCount(1);
});

it('credits a request-only assertion with the operation and with no response of it', function (): void {
    // assertValidRequest() checks nothing that came back, so crediting a response would be the
    // too-generous number the whole report exists to stop.
    ApiContract::assertions()->assertValidRequest(contractResponse('GET', '/api/forms', body: '[]'));

    $exercised = ApiContract::coverage()->exercised();

    expect($exercised)->toHaveCount(1)
        ->and($exercised[0])->not->toContain('@')
        ->and(ApiContract::index()->operation($exercised[0])?->label())->toBe('GET /api/forms');

    $report = ApiContract::report();

    expect($report->exercisedOperations())->toBe(1)
        ->and($report->exercisedResponses())->toBe(0);
});

it('credits no response for an assertion that failed', function (): void {
    // The number reads generous exactly where it must not: the body violated the schema, the test went
    // red, and the response the suite has just DISPROVED would have counted as exercised.
    try {
        ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '{}'));
    } catch (AssertionFailedError) {
        // The point is what the recorder did with what it saw.
    }

    $exercised = ApiContract::coverage()->exercised();
    $report = ApiContract::report();

    expect($exercised)->toHaveCount(1)
        ->and($exercised[0])->not->toContain('@')
        ->and($report->exercisedResponses())->toBe(0)
        ->and($report->exercisedOperations())->toBe(1);
});

it('credits a response from what the check proved, not from the status it answered', function (?Outcome $outcome, ?string $credited): void {
    $operation = ApiContract::index()->match('GET', '/api/forms');
    $response = contractResponse('GET', '/api/forms', body: '[]');
    $request = $response->baseRequest;

    $recorder = new CoverageRecorder;
    $recorder->observed(new ObservedExchange(
        operation: $operation,
        exchange: ApiContract::exchangeFor($request, $response),
        request: $request,
        response: $response,
        result: new CheckResult($operation, null, $outcome),
    ));

    expect($recorder->exercised())->toBe([$operation->id.$credited]);
})->with([
    // Every shape an Outcome comes in, and what each one honestly proves about the response.
    'a response the schema accepted' => [Outcome::passed(), '@200'],
    // A note is the CONTRACT saying it cannot be checked — a text/csv body, a media type with no
    // schema. No assertion a suite could write closes that, so refusing the credit would leave the
    // endpoint permanently uncoverable and a 100% floor out of reach for a defect in the document.
    'a response nothing could check' => [Outcome::passed('the response body is text/csv, which JSON Schema cannot check'), '@200'],
    'a response that disagreed' => [Outcome::failed([Violation::ofExchange('nope')]), ''],
    'a half that never ran' => [null, ''],
]);

/*
 * The outbound half, held to the same rule. A delivery reaches the recorder by a road of its own — no
 * TestResponse, no observers, and no `$result->ok()` to hang off — so the two halves are pinned side by
 * side here: what a delivery earns is what the check PROVED about it, never that a test asserted about
 * it and went red.
 */
it('credits a delivery from what the check proved, not from having asserted about it', function (?string $fixture, string $name, mixed $payload, bool $credited): void {
    app()->setBasePath(dirname(__DIR__, 3));
    workbenchContract(static function (array $raw) use ($fixture): array {
        $raw['webhooks'] = ['dir' => $fixture ?? 'workbench/app/Webhooks'];

        return $raw;
    });

    $webhooks = ApiContract::index()->webhooksNamed($name);
    $id = $webhooks === [] ? null : $webhooks[0]->id;

    try {
        // The note row says so on the run's warning channel; standing in front of it keeps this test
        // about the credit rather than about the warning.
        warningsRaisedBy(static fn () => ApiContract::assertions()->assertValidWebhook($name, $payload));
    } catch (AssertionFailedError) {
        // The point is what the recorder did with what it saw.
    }

    expect(ApiContract::coverage()->exercised())->toBe($credited ? [(string) $id] : [])
        ->and(ApiContract::report()->exercisedResponses())->toBe($credited ? 1 : 0);
})->with([
    'a payload the schema accepted' => [null, 'form.submitted', ['formId' => 7, 'submittedAt' => '2026-01-01T00:00:00Z'], true],
    // A note is the CONTRACT saying it cannot be checked — here a text/csv delivered body. Same answer
    // as the response half, for the same reason: no assertion a suite could write closes a gap in the
    // document, and refusing the credit would leave the webhook permanently uncoverable.
    'a delivery nothing could check' => ['tests/Fixtures/Webhooks/Uncheckable', 'report.ready', ['reference' => 'RPT-1'], true],
    'a payload that violated the documented body' => [null, 'form.submitted', ['formId' => 'seven', 'submittedAt' => 'now'], false],
    // Fails before there is an Outcome at all, which is a delivery even less proved than a failed one.
    'a payload no encoder could turn into bytes' => [null, 'form.submitted', ['handle' => fopen('php://memory', 'r')], false],
    'a name the document does not publish' => [null, 'invoice.paid', ['id' => 1], false],
]);

/*
 * …and the credit is recorded BEFORE the note is raised, which only a suite that fails on warnings can
 * tell apart. The ordinary handler swallows the note and both orders look identical; a suite that turns
 * one into a failure would, with the record behind it, lose the credit for a check that had already
 * proved the delivery — reversing a decision the row above states on purpose.
 */
it('records what a delivery proved before raising the note that can fail the test', function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    workbenchContract(static function (array $raw): array {
        $raw['webhooks'] = ['dir' => 'tests/Fixtures/Webhooks/Uncheckable'];

        return $raw;
    });

    // What `failOnWarning` amounts to from inside the call: the note becomes a throw.
    set_error_handler(static function (int $severity, string $message): bool {
        throw new RuntimeException($message);
    }, E_USER_WARNING);

    try {
        expect(static fn () => ApiContract::assertions()->assertValidWebhook('report.ready', ['reference' => 'RPT-1']))
            ->toThrow(RuntimeException::class);
    } finally {
        restore_error_handler();
    }

    expect(ApiContract::coverage()->exercised())->toHaveCount(1);
});

it('reports the responses the run never touched, in the document’s own order', function (): void {
    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));

    $report = ApiContract::report();
    $missing = array_map(static fn ($row): string => $row->label, $report->missing());

    expect($report->exercisedResponses())->toBe(1)
        ->and($report->exercisedOperations())->toBe(1)
        ->and($report->totalResponses())->toBeGreaterThan($report->totalOperations())
        ->and($missing)->toContain('POST /api/widgets')
        ->and($missing)->toBe(array_values(array_unique($missing)));

    // The operation whose 200 was just proved is still listed, because its documented error responses
    // are promises nothing in this run kept.
    $forms = array_values(array_filter($report->rows, static fn ($row): bool => $row->label === 'GET /api/forms'));

    expect($forms)->toHaveCount(1)
        ->and($forms[0]->exercised)->toBeTrue()
        ->and(array_map(static fn ($response): ?string => $response->status, $forms[0]->unexercised()))
        ->not->toContain('200');
});

it('writes nothing until a bootstrap asks it to', function (): void {
    // The recorder is always on — ApiContract::report() reads it — but a package that started writing
    // files into an application nobody asked would be a package people turn off.
    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));

    expect(ApiContract::coverage()->logPath())->toBeNull()
        ->and(CoverageLog::scan($this->root)->files)->toBe([]);
});

it('logs what this process exercised, once per entry, where the bootstrap said', function (): void {
    ApiContract::recordCoverage($this->root);

    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));
    ApiContract::assertions()->assertValidExchange(contractResponse('GET', '/api/forms', body: '[]'));
    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms/1', body: '{"id":1,"title":"a","publishedAt":null}'));

    $path = ApiContract::coverage()->logPath();

    expect($path)->toStartWith($this->root.'/')
        ->and(substr_count((string) file_get_contents((string) $path), "\n"))->toBe(2)
        ->and(CoverageMerge::of([$this->root])->entries)->toBe(ApiContract::coverage()->exercised());
});

it('names its log after the worker the runner says it is', function (): void {
    // Where a token exists it is used, because a directory of `w3.…` reads better than one of hashes.
    // Nothing is gated on it: the file is unique with or without one.
    $previous = getenv('UNIQUE_TEST_TOKEN');
    putenv('UNIQUE_TEST_TOKEN=9');

    try {
        expect(ParallelRun::worker())->toBe('9');

        ApiContract::recordCoverage($this->root);
        ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));

        expect(basename((string) ApiContract::coverage()->logPath()))->toStartWith('9.');
    } finally {
        is_string($previous) ? putenv('UNIQUE_TEST_TOKEN='.$previous) : putenv('UNIQUE_TEST_TOKEN');
    }
});

it('merges N workers into exactly what one process would have said, in any order', function (): void {
    // The proof the whole redesign rests on. One process records everything; three "workers" split the
    // same operations between them, each writing a log of its own — and the merged report has to be the
    // single-process report, whatever order the directories reach the merge in.
    $ids = array_values(array_filter(array_map(
        static fn ($operation): ?string => $operation->id,
        ApiContract::index()->operations(),
    )));

    expect(count($ids))->toBeGreaterThan(6);

    $exercised = array_slice($ids, 0, 6);

    $solo = (new CoverageRecorder)->logTo($this->root.'/single');
    foreach ($exercised as $id) {
        $solo->record($id, 200);
    }

    // Two of the three met the same operation, and one met its share out of document order: both are
    // ordinary, and neither may reach the answer.
    $shares = [
        '1' => [$exercised[0], $exercised[3], $exercised[1]],
        '2' => [$exercised[1], $exercised[4]],
        '3' => [$exercised[5], $exercised[2], $exercised[0]],
    ];

    $directories = [];
    foreach ($shares as $token => $share) {
        $worker = (new CoverageRecorder)->logTo($this->root.'/worker-'.$token);

        foreach ($share as $id) {
            $worker->record($id, 200);
        }

        $directories[] = $this->root.'/worker-'.$token;
    }

    $expected = CoverageReport::of(ApiContract::index(), CoverageMerge::of([$this->root.'/single'])->entries);
    $orders = permutationsOf($directories);

    expect($orders)->toHaveCount(6)
        ->and($expected->exercisedOperations())->toBe(6);

    foreach ($orders as $order) {
        $merge = CoverageMerge::of($order);
        $report = CoverageReport::of(ApiContract::index(), $merge->entries);

        expect($merge->complete())->toBeTrue()
            ->and($merge->files)->toHaveCount(3)
            ->and($report->render(80.0))->toBe($expected->render(80.0))
            ->and($report->percentage())->toBe($expected->percentage());
    }
});

it('forgets what it recorded when the suite resets', function (): void {
    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));
    expect(ApiContract::coverage()->exercised())->toHaveCount(1);

    ApiContract::coverage()->forget();
    expect(ApiContract::coverage()->exercised())->toBe([]);

    ApiContract::coverage()->record('op:v1:zzzzzzzzzzzzzzzz', 404);
    ApiContract::coverage()->record('op:v1:aaaaaaaaaaaaaaaa', 200);
    expect(ApiContract::coverage()->exercised())->toBe(['op:v1:aaaaaaaaaaaaaaaa@200', 'op:v1:zzzzzzzzzzzzzzzz@404']);
});

it('drops a recorded string that is not an operation id rather than writing it down', function (): void {
    // record() is public, and a log line is held to the entry shape when it is read back — so a stray
    // string reaching the log would condemn the whole file its process wrote. Nothing is lost: an id
    // that is not an operation's matches no operation in the report either.
    ApiContract::recordCoverage($this->root);

    ApiContract::coverage()->record('GET /api/forms', 200);
    ApiContract::coverage()->record('op:v1:aaaa', 200);
    ApiContract::coverage()->record('sch:v1:aaaaaaaaaaaaaaaa', 200);

    expect(ApiContract::coverage()->exercised())->toBe([])
        ->and(CoverageLog::scan($this->root)->files)->toBe([]);
});

it('widens a status the log cannot carry to the operation, rather than losing the run', function (): void {
    ApiContract::coverage()->record('op:v1:aaaaaaaaaaaaaaaa', 0);

    expect(ApiContract::coverage()->exercised())->toBe(['op:v1:aaaaaaaaaaaaaaaa']);
});

it('detects a parallel runner and which worker it is', function (): void {
    $previous = [];
    foreach (['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN'] as $variable) {
        $previous[$variable] = getenv($variable);
        putenv($variable);
    }

    try {
        expect(ParallelRun::active())->toBeFalse();

        putenv('PARATEST=1');
        expect(ParallelRun::active())->toBeTrue()
            ->and(ParallelRun::worker())->toBeNull();

        putenv('TEST_TOKEN=3');
        expect(ParallelRun::worker())->toBe('3');

        putenv('UNIQUE_TEST_TOKEN=abc');
        expect(ParallelRun::worker())->toBe('abc');
    } finally {
        foreach ($previous as $variable => $value) {
            is_string($value) ? putenv($variable.'='.$value) : putenv($variable);
        }
    }
});
