<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * `diagnostics.accept`: which reports stop failing a build, which never can, and how an entry stays
 * visible enough to be deleted when it stops earning its place.
 *
 * Two shapes of the workbench document carry the whole matrix. Narrowed to `api/widget-query` its
 * loudest report is one `info` — the rung a gate on inference certainty lives at, and the one a team
 * realistically wants to accept. Whole, it also reports a `route.build-failed` error, which is what
 * acceptance must never be able to touch.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

/** The temporary artifact a run has to write somewhere; the bytes are not what these tests read. */
function acceptanceOut(): string
{
    return sys_get_temp_dir().'/docuccino-accept-'.uniqid().'.json';
}

function onlyTheRecoveringRoute(): void
{
    config()->set('docuccino.documents.default.routes.include', ['api/widget-query']);
}

it('lets an accepted code through every floor that would otherwise catch it', function (string $failOn, bool $failsUnaccepted): void {
    onlyTheRecoveringRoute();
    $out = acceptanceOut();

    // Unaccepted first, so each row proves the floor really does see the diagnostic it is accepting.
    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => $failOn])
        ->{$failsUnaccepted ? 'assertFailed' : 'assertSuccessful'}();

    config()->set('docuccino.diagnostics.accept', ['query-builder.default-config']);

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => $failOn])->assertSuccessful();

    @unlink($out);
})->with([
    'none' => ['none', false],
    'error' => ['error', false],
    'warning' => ['warning', false],
    'info' => ['info', true],
    'hint' => ['hint', true],
]);

it('keeps printing what it accepted, and totals it', function (): void {
    onlyTheRecoveringRoute();
    config()->set('docuccino.diagnostics.accept', ['query-builder.default-config']);
    $out = acceptanceOut();

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => 'info'])
        ->expectsOutputToContain('[info, accepted] query-builder.default-config')
        ->expectsOutputToContain('Accepted, so --fail-on ignores them: query-builder.default-config (1)')
        ->assertSuccessful();

    @unlink($out);
});

it('marks nothing accepted when nothing is', function (): void {
    onlyTheRecoveringRoute();
    $out = acceptanceOut();

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => 'none'])
        ->expectsOutputToContain('[info] query-builder.default-config')
        ->doesntExpectOutputToContain('accepted')
        ->assertSuccessful();

    @unlink($out);
});

/*
 * The rule the whole feature is bounded by. An error says the document is wrong or the build lost a
 * tier of facts; a list in a config file must not be able to ship that quietly.
 */
it('never accepts an error, at any floor that can see one', function (string $failOn): void {
    config()->set('docuccino.diagnostics.accept', ['route.build-failed']);
    $out = acceptanceOut();

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => $failOn])
        ->expectsOutputToContain('[error] route.build-failed')
        ->assertFailed();

    @unlink($out);
})->with(['error', 'warning', 'info', 'hint']);

it('says why an accepted code failed the run anyway', function (): void {
    config()->set('docuccino.diagnostics.accept', ['route.build-failed']);
    $out = acceptanceOut();

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => 'error'])
        ->expectsOutputToContain("[warning] config.accept-refused: diagnostics.accept names 'route.build-failed', which this build reported as an error")
        ->assertFailed();

    @unlink($out);
});

it('reports an entry nothing fired, whether the cause is fixed or the code is misspelled', function (string $code): void {
    onlyTheRecoveringRoute();
    config()->set('docuccino.diagnostics.accept', [$code]);
    $out = acceptanceOut();

    // Visible at every floor: the report is how the list is kept honest, not a gate of its own.
    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => 'none'])
        ->expectsOutputToContain(sprintf("[warning] config.accept-unused: diagnostics.accept names '%s', which nothing reported in this build.", $code))
        ->assertSuccessful();

    // …and it is a warning, so a run gating on warnings does not stay green around a dead entry.
    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => 'warning'])->assertFailed();

    @unlink($out);
})->with([
    'a code this build no longer reports' => ['lint.data-leakage'],
    'a code no version of Docuccino has' => ['not-a.code'],
]);

it('says nothing about a stale entry while one is firing', function (): void {
    onlyTheRecoveringRoute();
    config()->set('docuccino.diagnostics.accept', ['query-builder.default-config']);
    $out = acceptanceOut();

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => 'warning'])
        ->doesntExpectOutputToContain('config.accept-unused')
        ->assertSuccessful();

    @unlink($out);
});

/*
 * A run narrowed to one document cannot tell an entry nothing reports from one the document it
 * skipped reports on every build, so it says nothing rather than guessing.
 */
it('checks a stale entry only once the run has covered every document', function (): void {
    $defaultOut = acceptanceOut();
    $secondOut = acceptanceOut();

    config()->set('docuccino.documents', [
        'default' => [
            'info' => ['title' => 'API Documentation', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/widget-query']],
            'export' => ['path' => $defaultOut],
        ],
        'second' => [
            'info' => ['title' => 'Second', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/widget-query']],
            'export' => ['path' => $secondOut],
        ],
    ]);
    config()->set('docuccino.diagnostics.accept', ['not-a.code']);

    $this->artisan('docuccino:export', ['document' => 'default'])
        ->doesntExpectOutputToContain('config.accept-unused')
        ->assertSuccessful();

    $this->artisan('docuccino:export')
        ->expectsOutputToContain('config.accept-unused')
        ->assertSuccessful();

    @unlink($defaultOut);
    @unlink($secondOut);
});

it('reads the same list on validate', function (): void {
    onlyTheRecoveringRoute();

    $this->artisan('docuccino:validate', ['--fail-on' => 'info'])->assertFailed();

    config()->set('docuccino.diagnostics.accept', ['query-builder.default-config']);

    $this->artisan('docuccino:validate', ['--fail-on' => 'info'])
        ->expectsOutputToContain('[info, accepted] query-builder.default-config')
        ->assertSuccessful();
});

it('cannot accept a validate failure any more than an export one', function (): void {
    config()->set('docuccino.diagnostics.accept', ['route.build-failed']);

    $this->artisan('docuccino:validate', ['--fail-on' => 'error'])
        ->expectsOutputToContain('config.accept-refused')
        ->assertFailed();
});
