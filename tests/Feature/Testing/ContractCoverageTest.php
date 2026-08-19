<?php

declare(strict_types=1);

use Docuccino\Core\Contract\Coverage\CoverageLog;
use Docuccino\Core\Contract\Coverage\CoverageMerge;
use Docuccino\Core\Contract\Coverage\CoverageReport;
use Docuccino\Laravel\Testing\ApiContract;
use Docuccino\Laravel\Testing\CoverageRecorder;
use Docuccino\Laravel\Testing\ParallelRun;

/*
 * Contract coverage: what a process records, what it writes down, and — the whole point of writing it
 * down — that N workers' logs merge to exactly what one process would have said.
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

it('records the operations the run exercised, by id', function (): void {
    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));

    $exercised = ApiContract::coverage()->exercised();

    expect($exercised)->toHaveCount(1)
        ->and($exercised[0])->toStartWith('op:v1:')
        ->and(ApiContract::index()->operation($exercised[0])?->label())->toBe('GET /api/forms');
});

it('records an operation once however many times the suite hits it', function (): void {
    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));
    ApiContract::assertions()->assertValidExchange(contractResponse('GET', '/api/forms', body: '[]'));

    expect(ApiContract::coverage()->exercised())->toHaveCount(1);
});

it('reports what the run never touched, in the document’s own order', function (): void {
    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));

    $report = ApiContract::report();
    $missing = array_map(static fn ($row): string => $row->label, $report->missing());

    expect($report->exercisedCount())->toBe(1)
        ->and($report->total())->toBeGreaterThan(1)
        ->and($missing)->not->toContain('GET /api/forms')
        ->and($missing)->toContain('POST /api/widgets')
        ->and($missing)->toBe(array_values(array_unique($missing)));
});

it('writes nothing until a bootstrap asks it to', function (): void {
    // The recorder is always on — ApiContract::report() reads it — but a package that started writing
    // files into an application nobody asked would be a package people turn off.
    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));

    expect(ApiContract::coverage()->logPath())->toBeNull()
        ->and(CoverageLog::scan($this->root)->files)->toBe([]);
});

it('logs what this process exercised, once per id, where the bootstrap said', function (): void {
    ApiContract::recordCoverage($this->root);

    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));
    ApiContract::assertions()->assertValidExchange(contractResponse('GET', '/api/forms', body: '[]'));
    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms/1', body: '{"id":1,"title":"a","publishedAt":null}'));

    $path = ApiContract::coverage()->logPath();

    expect($path)->toStartWith($this->root.'/')
        ->and(substr_count((string) file_get_contents((string) $path), "\n"))->toBe(2)
        ->and(CoverageMerge::of([$this->root])->ids)->toBe(ApiContract::coverage()->exercised());
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
        $solo->record($id);
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
            $worker->record($id);
        }

        $directories[] = $this->root.'/worker-'.$token;
    }

    $expected = CoverageReport::of(ApiContract::index(), CoverageMerge::of([$this->root.'/single'])->ids);
    $orders = permutationsOf($directories);

    expect($orders)->toHaveCount(6)
        ->and($expected->exercisedCount())->toBe(6);

    foreach ($orders as $order) {
        $merge = CoverageMerge::of($order);
        $report = CoverageReport::of(ApiContract::index(), $merge->ids);

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

    ApiContract::coverage()->record('op:v1:zzzzzzzzzzzzzzzz');
    ApiContract::coverage()->record('op:v1:aaaaaaaaaaaaaaaa');
    expect(ApiContract::coverage()->exercised())->toBe(['op:v1:aaaaaaaaaaaaaaaa', 'op:v1:zzzzzzzzzzzzzzzz']);
});

it('drops a recorded string that is not an operation id rather than writing it down', function (): void {
    // record() is public, and a log line is held to the id shape when it is read back — so a stray
    // string reaching the log would condemn the whole file its process wrote. Nothing is lost: an id
    // that is not an operation's matches no operation in the report either.
    ApiContract::recordCoverage($this->root);

    ApiContract::coverage()->record('GET /api/forms');
    ApiContract::coverage()->record('op:v1:aaaa');
    ApiContract::coverage()->record('sch:v1:aaaaaaaaaaaaaaaa');

    expect(ApiContract::coverage()->exercised())->toBe([])
        ->and(CoverageLog::scan($this->root)->files)->toBe([]);
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
