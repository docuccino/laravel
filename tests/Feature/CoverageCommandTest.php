<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Coverage\CoverageLog;
use Docuccino\Core\Contract\Coverage\CoverageReport;
use Docuccino\Core\Emit\UirEmitter;

/*
 * docuccino:coverage — the post-run half of contract coverage. It reads the artifact the suite
 * asserted against, unions the logs the suite wrote, and gates. It never builds a document, so nothing
 * here needs an engine beyond the one that wrote the fixture artifact.
 */
beforeEach(function (): void {
    bindStubEngine();

    $this->root = coverageFixtureDir('command');
    $this->logs = $this->root.'/logs';
    $this->artifact = $this->root.'/api.uir.json';

    file_put_contents($this->artifact, (new UirEmitter)->emit(generateDocument()->document));

    config()->set('docuccino.documents.default.export.targets', [['format' => 'uir', 'path' => $this->artifact]]);
    config()->set('docuccino.documents.default.coverage.log', $this->logs);

    $index = ContractIndex::fromJson((string) file_get_contents($this->artifact));
    $this->operations = $index->operations();

    // Every documented response the workbench promises, as the entry a suite that exercised it would
    // have written — the status found by asking the operation which response it would resolve to, so the
    // fixture and the report read one grammar. Derived from the artifact rather than listed, so a
    // document that grows a response grows the denominator here too.
    $this->entries = [];
    $this->happy = [];

    foreach ($this->operations as $operation) {
        // An operation documenting no response promises nothing a status could name, so the only thing
        // to record is that it was reached — which is the bare entry.
        $statuses = $operation->responseKeys() === [] ? [null] : [];

        foreach ($operation->responseKeys() as $key) {
            for ($status = 100; $status <= 599; $status++) {
                if ($operation->responseKeyFor($status) === $key) {
                    $statuses[] = $status;

                    break;
                }
            }
        }

        foreach ($statuses as $status) {
            $entry = CoverageLog::entry((string) $operation->id, $status);

            if ($entry !== null) {
                $this->entries[] = $entry;
                $this->happy[(string) $operation->id] ??= $entry;
            }
        }
    }

    $this->total = count($this->entries);
    $this->happy = array_values($this->happy);

    // A derivation that stopped deriving would make every count below vacuous, and the whole point is
    // that a document promises more responses than it has operations.
    expect($this->total)->toBeGreaterThan(count($this->operations))
        ->and(CoverageReport::of($index, $this->entries)->complete())->toBeTrue();
});

afterEach(function (): void {
    removeCoverageFixture($this->root);
});

it('reports what the logs cover, out of the document the suite asserted against', function (): void {
    CoverageLog::for($this->logs, '1')->append(array_slice($this->entries, 0, 2));
    CoverageLog::for($this->logs, '2')->append(array_slice($this->entries, 1, 2));

    $this->artisan('docuccino:coverage')
        ->expectsOutputToContain('Coverage — default')
        ->expectsOutputToContain('3 of '.$this->total.' documented responses and webhook deliveries exercised')
        ->expectsOutputToContain('documented operations were reached at all')
        ->expectsOutputToContain('Never exercised:')
        ->assertSuccessful();
});

it('gates on responses, not on operations — every endpoint hit and the errors still owed', function (): void {
    // The defect this number exists to catch: a suite that only ever asserts the happy path reaches every
    // operation and has proved none of the error responses a consumer writes a catch against. Under the
    // old number that was 100% coverage.
    expect(count($this->happy))->toBe(count($this->operations))
        ->and(count($this->happy))->toBeLessThan($this->total);

    CoverageLog::for($this->logs, '1')->append($this->happy);

    $this->artisan('docuccino:coverage', ['--min' => '100'])
        ->expectsOutputToContain(count($this->happy).' of '.$this->total.' documented responses and webhook deliveries exercised')
        // One expectation, not two: Mockery routes an output line to the first expectation that matches
        // it, so a second substring of the same line would never be seen.
        ->expectsOutputToContain(sprintf(
            '%d of %d documented operations were reached at all — the floor is measured against responses and deliveries, not operations.',
            count($this->operations),
            count($this->operations),
        ))
        ->assertFailed();
});

it('names an operation that documents no response at all, rather than dropping it from the listing', function (): void {
    // A response-granular listing has nothing to name for an operation promising no response, and an
    // endpoint nobody ever called must not vanish out of the report for want of a status.
    $silent = array_values(array_filter($this->operations, static fn ($operation): bool => $operation->responseKeys() === []));

    expect($silent)->not->toBeEmpty();

    CoverageLog::for($this->logs, '1')->append([$this->happy[0]]);

    $this->artisan('docuccino:coverage')
        ->expectsOutputToContain('(no responses documented)  '.$silent[0]->id)
        ->assertSuccessful();
});

it('finds the logs the document configured without being told where they are', function (): void {
    // The suite writing and the command reading resolve `coverage.log` through one resolver, so a
    // recipe that names the directory nowhere still works.
    CoverageLog::for($this->logs, '1')->append($this->entries);

    $this->artisan('docuccino:coverage', ['--min' => '100'])->assertSuccessful();
});

it('fails below the floor and passes at it', function (string $minimum, bool $fails): void {
    CoverageLog::for($this->logs, '1')->append(array_slice($this->entries, 0, (int) floor($this->total / 2)));

    $command = $this->artisan('docuccino:coverage', ['--min' => $minimum]);
    $fails ? $command->assertFailed() : $command->assertSuccessful();
})->with([
    'no floor passes whatever it measures' => ['0', false],
    'a floor under the measurement passes' => ['10', false],
    'a floor over the measurement fails' => ['90', true],
    'every operation fails' => ['100', true],
]);

it('refuses a floor that is not a percentage', function (string $minimum): void {
    CoverageLog::for($this->logs, '1')->append($this->entries);

    $this->artisan('docuccino:coverage', ['--min' => $minimum])
        ->expectsOutputToContain('--min must be a percentage between 0 and 100')
        ->assertFailed();
})->with([['abc'], ['-1'], ['101']]);

it('merges the directories it is given, in any order, and says the same thing each time', function (): void {
    CoverageLog::for($this->root.'/shard-1', '1')->append(array_slice($this->entries, 0, 2));
    CoverageLog::for($this->root.'/shard-2', '1')->append(array_slice($this->entries, 2, 2));

    foreach ([['shard-1', 'shard-2'], ['shard-2', 'shard-1']] as $order) {
        $this->artisan('docuccino:coverage', ['--path' => array_map(fn (string $s): string => $this->root.'/'.$s, $order)])
            ->expectsOutputToContain('4 of '.$this->total.' documented responses and webhook deliveries exercised')
            ->expectsOutputToContain('2 log files, 4 entries')
            ->assertSuccessful();
    }
});

it('refuses to gate on a merge it could not complete', function (array $paths, string $says): void {
    // A number computed from three of four shards is worse than no number, so an incomplete merge never
    // reaches a percentage at all.
    CoverageLog::for($this->root.'/shard-1', '1')->append($this->entries);
    mkdir($this->root.'/shard-blank', 0755, true);
    file_put_contents($this->root.'/shard-1/torn.0.deadbeef.ids', "\x00");

    $this->artisan('docuccino:coverage', ['--min' => '0', '--path' => array_map(fn (string $s): string => $this->root.'/'.$s, $paths)])
        ->expectsOutputToContain('The coverage logs are incomplete')
        ->expectsOutputToContain($says)
        ->doesntExpectOutputToContain('documented responses and webhook deliveries exercised')
        ->assertFailed();
})->with([
    'a shard whose logs never arrived' => [['shard-1', 'shard-missing'], 'could not be read'],
    'a shard that logged nothing' => [['shard-blank'], 'holds no coverage log'],
    'a torn log' => [['shard-1'], 'not readable as a coverage log'],
]);

it('says so when the logs span longer than a run, and stays quiet when they do not', function (): void {
    // Nothing in the counts betrays a forgotten --reset: three runs union exactly like one, and too
    // generous is the worse direction for a gate. How far apart the files were written is the only tell.
    CoverageLog::for($this->logs, '1')->append(array_slice($this->entries, 0, 2));

    $this->artisan('docuccino:coverage')
        ->doesntExpectOutputToContain('These logs span')
        ->assertSuccessful();

    $stale = $this->logs.'/2.0.deadbeef.ids';
    file_put_contents($stale, implode("\n", array_slice($this->entries, 2, 1))."\n");

    // Both mtimes come off one base, so the span is exactly 2h30m however long the run above took —
    // offset one of them from a second time() and a second spent here reads as 2h 29m.
    $base = time();
    foreach (CoverageLog::scan($this->logs)->files as $log) {
        touch($log, $base);
    }
    touch($stale, $base - 9000);
    clearstatcache();

    $this->artisan('docuccino:coverage')
        ->expectsOutputToContain('These logs span 2h 30m')
        ->assertSuccessful();
});

it('says where to look when no suite has ever written a log', function (): void {
    $this->artisan('docuccino:coverage')
        ->expectsOutputToContain('The coverage logs are incomplete')
        ->expectsOutputToContain('ApiContract::recordCoverage()')
        ->assertFailed();
});

it('names the artifact to export when there is none to measure against', function (): void {
    CoverageLog::for($this->logs, '1')->append($this->entries);
    unlink($this->artifact);

    $this->artisan('docuccino:coverage')
        ->expectsOutputToContain('to measure coverage against')
        ->expectsOutputToContain('php artisan docuccino:export')
        ->assertFailed();
});

it('names the artifact to regenerate when it is not a document', function (): void {
    CoverageLog::for($this->logs, '1')->append($this->entries);
    file_put_contents($this->artifact, 'not json');

    $this->artisan('docuccino:coverage')
        ->expectsOutputToContain('is not a JSON document')
        ->assertFailed();
});

it('empties the logs on --reset and leaves everything else alone', function (): void {
    // Names are unique per writing process so two shards never overwrite each other, and the price of
    // that is that runs accumulate. This is how a recipe pays it.
    CoverageLog::for($this->logs, '1')->append($this->entries);
    file_put_contents($this->logs.'/notes.txt', 'not a log');

    $this->artisan('docuccino:coverage', ['--reset' => true])
        ->expectsOutputToContain('default: removed 1 coverage log(s).')
        ->assertSuccessful();

    expect(CoverageLog::scan($this->logs)->files)->toBe([])
        ->and(is_file($this->logs.'/notes.txt'))->toBeTrue();

    $this->artisan('docuccino:coverage', ['--reset' => true])
        ->expectsOutputToContain('default: removed 0 coverage log(s).')
        ->assertSuccessful();
});

it('fails for an unknown document, and stops when Docuccino is disabled', function (): void {
    $this->artisan('docuccino:coverage', ['document' => 'nope'])->assertFailed();

    config()->set('docuccino.enabled', false);

    $this->artisan('docuccino:coverage')
        ->expectsOutputToContain('Docuccino is disabled')
        ->assertFailed();
});
