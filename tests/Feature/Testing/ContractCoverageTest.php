<?php

declare(strict_types=1);

use Docuccino\Laravel\Testing\ApiContract;
use Docuccino\Laravel\Testing\ParallelRun;
use PHPUnit\Framework\AssertionFailedError;

/*
 * These tests are about what coverage SAYS, so they run with the runner's own markers cleared and put
 * back afterwards — otherwise every one of them would take the parallel refusal branch.
 */
beforeEach(function (): void {
    $this->runner = [];

    foreach (['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN'] as $variable) {
        $this->runner[$variable] = getenv($variable);
        putenv($variable);
    }
});

afterEach(function (): void {
    foreach ($this->runner as $variable => $value) {
        is_string($value) ? putenv($variable.'='.$value) : putenv($variable);
    }

    @unlink(sys_get_temp_dir().'/docuccino-contract-'.getmypid().'.uir.json');
    ApiContract::reset();
});

it('records the operations the run exercised, by id', function (): void {
    workbenchContract();

    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));

    $exercised = ApiContract::coverage()->exercised();

    expect($exercised)->toHaveCount(1)
        ->and($exercised[0])->toStartWith('op:v1:')
        ->and(ApiContract::index()->operation($exercised[0])?->label())->toBe('GET /api/forms');
});

it('records an operation once however many times the suite hits it', function (): void {
    workbenchContract();

    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));
    ApiContract::assertions()->assertValidExchange(contractResponse('GET', '/api/forms', body: '[]'));

    expect(ApiContract::coverage()->exercised())->toHaveCount(1);
});

it('reports what the run never touched, in the document’s own order', function (): void {
    workbenchContract();

    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));

    $report = ApiContract::report();
    $missing = array_map(static fn ($row): string => $row->label, $report->missing());

    expect($report->exercisedCount())->toBe(1)
        ->and($report->total())->toBeGreaterThan(1)
        ->and($missing)->not->toContain('GET /api/forms')
        ->and($missing)->toContain('POST /api/widgets')
        ->and($missing)->toBe(array_values(array_unique($missing)));
});

it('fails the coverage gate with the report as its message', function (): void {
    workbenchContract();

    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));

    try {
        ApiContract::assertions()->assertEveryOperationExercised();
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('Docuccino contract coverage:')
            ->toContain('floor 100%')
            ->toContain('Never exercised:')
            ->toContain('move the floor to');

        return;
    }

    throw new RuntimeException('the coverage gate should have failed');
});

it('clears a floor it meets', function (): void {
    workbenchContract();

    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));

    expect(fn () => ApiContract::assertions()->assertContractCoverage(0.0))->not->toThrow(AssertionFailedError::class);
});

it('refuses to measure coverage from inside a parallel run rather than inventing a gap', function (): void {
    workbenchContract();
    putenv('PARATEST=1');
    putenv('TEST_TOKEN=7');

    try {
        ApiContract::assertions()->assertContractCoverage(100.0);
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('cannot be measured from inside a parallel test run (worker 7)')
            ->toContain('none of them can know when the')
            ->toContain('drop --parallel');

        putenv('TEST_TOKEN');

        return;
    }

    putenv('TEST_TOKEN');

    throw new RuntimeException('the coverage gate should have refused');
});

it('detects a parallel runner and which worker it is', function (): void {
    expect(ParallelRun::active())->toBeFalse();

    putenv('PARATEST=1');
    expect(ParallelRun::active())->toBeTrue()
        ->and(ParallelRun::worker())->toBeNull();

    putenv('TEST_TOKEN=3');
    expect(ParallelRun::worker())->toBe('3');

    putenv('UNIQUE_TEST_TOKEN=abc');
    expect(ParallelRun::worker())->toBe('abc');

    putenv('TEST_TOKEN');
    putenv('UNIQUE_TEST_TOKEN');
});

it('forgets what it recorded when the suite resets', function (): void {
    workbenchContract();

    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));
    expect(ApiContract::coverage()->exercised())->toHaveCount(1);

    ApiContract::coverage()->forget();
    expect(ApiContract::coverage()->exercised())->toBe([]);

    ApiContract::coverage()->record('op:v1:zeta');
    ApiContract::coverage()->record('op:v1:alpha');
    expect(ApiContract::coverage()->exercised())->toBe(['op:v1:alpha', 'op:v1:zeta']);
});
