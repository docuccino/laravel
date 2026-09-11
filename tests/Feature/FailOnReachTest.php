<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * What `--fail-on` can see. The commands reference states the floor as "anything reported at that
 * severity or louder makes the exit code non-zero", and the configuration reference states
 * `diagnostics.accept` as the one thing that carves into it — neither of them scoped to one producer.
 * So a report the run PRINTS and the floor cannot see is the flag lying about itself, and an accept
 * entry the console marks as covering something it does not cover is the config lying too.
 *
 * The channels here are the two outside the build: what an emitter reports as it writes an artifact,
 * and what reading the export configuration reports before the build starts.
 *
 * Every test narrows to `api/checkout`, whose build is silent — so what a run reports is the channel
 * under test and nothing else, and a green row means the floor really did stay quiet.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
    setBuild('documents.default.routes.include', ['api/checkout']);
});

/** The artifact a run has to write somewhere; these tests read the exit code, not the bytes. */
function reachOut(): string
{
    return sys_get_temp_dir().'/docuccino-fail-on-reach-'.uniqid().'.json';
}

/**
 * `info.summary` is an OAS 3.2 member OpenAPI 3.0 does not define, so a 3.0 export drops it and says
 * so at warning. One config key, and the loss is the application's own — which is the shape of every
 * downlevel report a real build meets.
 */
function summaryTheDownlevelDrops(): void
{
    setBuild('documents.default.info.summary', 'Everything the widgets API can do.');
}

it('fails on a warning an emitter raised while writing the artifact', function (): void {
    summaryTheDownlevelDrops();
    $out = reachOut();

    $this->artisan('docuccino:export', ['--format' => 'openapi-3.0', '--out' => $out, '--fail-on' => 'warning'])
        ->expectsOutputToContain('downlevel.info-summary')
        ->assertFailed();

    // …and the default floor still ships it, so the report alone was never the gate.
    $this->artisan('docuccino:export', ['--format' => 'openapi-3.0', '--out' => $out])
        ->expectsOutputToContain('downlevel.info-summary')
        ->assertSuccessful();

    @unlink($out);
});

it('fails on an info an emitter raised, at the floor that reaches info and not at the one above it', function (): void {
    $out = reachOut();

    // The route's error body pins values with `const`, which 3.0 has to respell as a single-value enum.
    $this->artisan('docuccino:export', ['--format' => 'openapi-3.0', '--out' => $out, '--fail-on' => 'info'])
        ->expectsOutputToContain('downlevel.const')
        ->assertFailed();

    $this->artisan('docuccino:export', ['--format' => 'openapi-3.0', '--out' => $out, '--fail-on' => 'warning'])
        ->expectsOutputToContain('downlevel.const')
        ->assertSuccessful();

    @unlink($out);
});

it('lets diagnostics.accept silence an emitter warning, and says it did', function (): void {
    summaryTheDownlevelDrops();
    $out = reachOut();

    // Unaccepted first, so the accepted run below proves acceptance carved into a gate that was
    // really closed rather than agreeing with a run that was going to pass anyway.
    $this->artisan('docuccino:export', ['--format' => 'openapi-3.0', '--out' => $out, '--fail-on' => 'warning'])
        ->assertFailed();

    setBuild('diagnostics.accept', ['downlevel.info-summary']);

    $this->artisan('docuccino:export', ['--format' => 'openapi-3.0', '--out' => $out, '--fail-on' => 'warning'])
        ->expectsOutputToContain('[warning, accepted] downlevel.info-summary')
        ->expectsOutputToContain('Accepted, so --fail-on ignores them: downlevel.info-summary (1)')
        ->assertSuccessful();

    @unlink($out);
});

/**
 * The stale report reads the same set, so an entry only an emitter's channel reports is a live
 * acceptance rather than one the reader is told to delete.
 */
it('does not call an acceptance stale when only an emitter reported its code', function (): void {
    summaryTheDownlevelDrops();
    setBuild('diagnostics.accept', ['downlevel.info-summary']);
    $out = reachOut();

    $this->artisan('docuccino:export', ['--format' => 'openapi-3.0', '--out' => $out])
        ->doesntExpectOutputToContain('config.accept-unused')
        ->assertSuccessful();

    @unlink($out);
});

/**
 * Driven over both commands that read the export configuration. The channel is the configuration, not
 * the writing, so the floor has to read it identically wherever it is read — and the non-fatal half
 * is the one a run can only prove by staying green at the floor above.
 */
it('fails on an info raised by reading the export configuration, before any build', function (string $command, bool $writes): void {
    // `targets` supersedes `path`, so the path is never written — reported at info, and printed
    // before the analysis rather than by it, which is why it used to miss the gate too.
    setBuild('documents.default.export', [
        'path' => 'docs/superseded.json',
        'targets' => [['format' => 'openapi-3.2', 'path' => 'docs/openapi.json']],
    ]);
    $out = reachOut();
    $args = $writes ? ['--out' => $out] : [];

    $this->artisan($command, $args + ['--fail-on' => 'info'])
        ->expectsOutputToContain('config.export-path-ignored')
        ->assertFailed();

    $this->artisan($command, $args + ['--fail-on' => 'warning'])
        ->expectsOutputToContain('config.export-path-ignored')
        ->assertSuccessful();

    @unlink($out);
})->with([
    'docuccino:export' => ['docuccino:export', true],
    'docuccino:validate' => ['docuccino:validate', false],
]);

/**
 * The other half of a gate: where it must NOT fire. The shipped configuration writes one OpenAPI 3.2
 * artifact, which loses nothing on the way out, so widening what the floor reads has to leave the
 * out-of-the-box pipeline where it was — down to `--fail-on=hint`, the strictest value there is.
 */
it('stays green at every floor for the target the shipped configuration writes', function (string $failOn): void {
    $out = reachOut();

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => $failOn])->assertSuccessful();

    @unlink($out);
})->with(['none', 'error', 'warning', 'info', 'hint']);
