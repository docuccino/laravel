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
    setBuild('documents.default.routes.include', ['api/widget-query']);
}

it('lets an accepted code through every floor that would otherwise catch it', function (string $failOn, bool $failsUnaccepted): void {
    onlyTheRecoveringRoute();
    $out = acceptanceOut();

    // Unaccepted first, so each row proves the floor really does see the diagnostic it is accepting.
    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => $failOn])
        ->{$failsUnaccepted ? 'assertFailed' : 'assertSuccessful'}();

    setBuild('diagnostics.accept', ['query-builder.default-config']);

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
    setBuild('diagnostics.accept', ['query-builder.default-config']);
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
    setBuild('diagnostics.accept', ['route.build-failed']);
    $out = acceptanceOut();

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => $failOn])
        ->expectsOutputToContain('[error] route.build-failed')
        ->assertFailed();

    @unlink($out);
})->with(['error', 'warning', 'info', 'hint']);

it('says why an accepted code failed the run anyway', function (): void {
    setBuild('diagnostics.accept', ['route.build-failed']);
    $out = acceptanceOut();

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => 'error'])
        ->expectsOutputToContain("[warning] config.accept-refused: diagnostics.accept names 'route.build-failed', which this build reported as an error")
        ->assertFailed();

    @unlink($out);
});

it('files a dead acceptance entry against the file that holds the list', function (): void {
    onlyTheRecoveringRoute();
    setBuild('diagnostics.accept', ['not-a.code']);
    $out = acceptanceOut();

    // `diagnostics.accept` is build configuration, so the reader has to open docuccino.yaml to
    // delete the entry. A header naming the framework config would send them to a file that has
    // not held the list since the two files split.
    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => 'none'])
        ->expectsOutputToContain('Diagnostics for docuccino.yaml:')
        ->assertSuccessful();

    @unlink($out);
});

it('reports an entry nothing fired, whether the cause is fixed or the code is misspelled', function (string $code): void {
    onlyTheRecoveringRoute();
    setBuild('diagnostics.accept', [$code]);
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
    setBuild('diagnostics.accept', ['query-builder.default-config']);
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

    setDocuments([
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
    setBuild('diagnostics.accept', ['not-a.code']);

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

    setBuild('diagnostics.accept', ['query-builder.default-config']);

    $this->artisan('docuccino:validate', ['--fail-on' => 'info'])
        ->expectsOutputToContain('[info, accepted] query-builder.default-config')
        ->assertSuccessful();
});

it('cannot accept a validate failure any more than an export one', function (): void {
    setBuild('diagnostics.accept', ['route.build-failed']);

    $this->artisan('docuccino:validate', ['--fail-on' => 'error'])
        ->expectsOutputToContain('config.accept-refused')
        ->assertFailed();
});

/*
 * One code, two routes. `query-builder.default-config` fires on both query routes, so an entry scoped
 * to one of them is what tells a reviewed finding from an unreviewed one of the same code: the gate
 * has to keep catching the route nobody looked at.
 */
function bothQueryRoutes(): void
{
    setBuild('documents.default.routes.include', ['api/widget-query', 'api/ledger-query']);
}

it('accepts a code at the operation an entry names, and still fails on it everywhere else', function (): void {
    bothQueryRoutes();
    setBuild('diagnostics.accept', [
        ['code' => 'query-builder.default-config', 'operation' => 'GET /api/widget-query'],
        'query-builder.untyped-filter',
    ]);
    $out = acceptanceOut();

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => 'info'])
        ->expectsOutputToContain('[info, accepted] query-builder.default-config')
        ->expectsOutputToContain('[info] query-builder.default-config')
        ->expectsOutputToContain('Accepted, so --fail-on ignores them: query-builder.default-config (1), query-builder.untyped-filter (1)')
        ->doesntExpectOutputToContain('config.accept-unused')
        ->doesntExpectOutputToContain('config.unknown-setting')
        ->assertFailed();

    setBuild('diagnostics.accept', [
        ['code' => 'query-builder.default-config', 'operation' => 'GET /api/widget-query'],
        ['code' => 'query-builder.default-config', 'operation' => 'GET /api/ledger-query'],
        'query-builder.untyped-filter',
    ]);

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => 'info'])
        ->doesntExpectOutputToContain('[info] query-builder.default-config')
        ->assertSuccessful();

    @unlink($out);
});

it('reads a plain code as that code on every route, as it always has', function (): void {
    bothQueryRoutes();
    setBuild('diagnostics.accept', ['query-builder.default-config', 'query-builder.untyped-filter']);
    $out = acceptanceOut();

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => 'info'])
        ->expectsOutputToContain('Accepted, so --fail-on ignores them: query-builder.default-config (2), query-builder.untyped-filter (1)')
        ->doesntExpectOutputToContain('[info] query-builder.default-config')
        ->assertSuccessful();

    @unlink($out);
});

/*
 * A scoped entry outlives its route as easily as a code outlives its cause, and the code firing on
 * some other route is no evidence the entry still does anything.
 */
it('reports a scoped entry nothing reported at its operation, while the code fires elsewhere', function (): void {
    onlyTheRecoveringRoute();
    setBuild('diagnostics.accept', [
        'query-builder.default-config',
        ['code' => 'query-builder.default-config', 'operation' => 'GET /api/widget-query-renamed'],
    ]);
    $out = acceptanceOut();

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => 'none'])
        ->expectsOutputToContain("[warning] config.accept-unused: diagnostics.accept names 'query-builder.default-config' at 'GET /api/widget-query-renamed', which nothing reported in this build.")
        ->expectsOutputToContain('the route was renamed')
        ->assertSuccessful();

    @unlink($out);
});

it('says why an entry scoped to an operation failed the run anyway', function (): void {
    setBuild('diagnostics.accept', [['code' => 'route.build-failed', 'operation' => 'GET /api/broken']]);
    $out = acceptanceOut();

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => 'error'])
        ->expectsOutputToContain("[warning] config.accept-refused: diagnostics.accept names 'route.build-failed' at 'GET /api/broken', which this build reported as an error")
        ->assertFailed();

    @unlink($out);
});

/*
 * A misspelled scope must fail closed: read as the whole code it would quiet every finding of it, which
 * is the opposite of what its author wrote it for. The typo itself is the settings report's to name.
 */
it('accepts nothing for an entry whose scope is misspelled, and names the misspelling', function (): void {
    onlyTheRecoveringRoute();
    setBuild('diagnostics.accept', [['code' => 'query-builder.default-config', 'opration' => 'GET /api/widget-query']]);
    $out = acceptanceOut();

    $this->artisan('docuccino:export', ['--out' => $out, '--fail-on' => 'info'])
        ->expectsOutputToContain('[info] query-builder.default-config')
        ->expectsOutputToContain('config.unknown-setting: diagnostics.accept.0.opration names no setting')
        ->expectsOutputToContain('Did you mean diagnostics.accept.0.operation?')
        ->assertFailed();

    @unlink($out);
});
