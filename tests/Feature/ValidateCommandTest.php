<?php

declare(strict_types=1);

/**
 * Feature coverage for docuccino:validate — the workbench document validates against the bundled
 * UIR schema, an unknown document key fails, and the exit code honours the validation result.
 */
it('validates the workbench document against the bundled UIR schema', function (): void {
    bindStubEngine();

    $this->artisan('docuccino:validate')
        ->expectsOutputToContain('default: valid against UIR 1.0.0.')
        ->assertSuccessful();
});

it('validates a single named document', function (): void {
    bindStubEngine();

    $this->artisan('docuccino:validate', ['document' => 'default'])
        ->assertSuccessful();
});

it('fails for an unknown document', function (): void {
    bindStubEngine();

    $this->artisan('docuccino:validate', ['document' => 'nope'])
        ->assertFailed();
});

/**
 * `--fail-on` is the command's *extra* gate, independent of the schema check the workbench passes.
 * The `info` rung is the one that reaches a recovery report, so it is the one a pipeline gating on
 * inference certainty reaches for.
 */
it('applies the --fail-on floor on top of a valid document', function (string $failOn, bool $fails): void {
    bindStubEngine();
    config()->set('docuccino.documents.default.routes.include', ['api/widget-query']);

    $command = $this->artisan('docuccino:validate', ['--fail-on' => $failOn]);
    $fails ? $command->assertFailed() : $command->assertSuccessful();
})->with([
    'none exits 0' => ['none', false],
    'error does not see an info' => ['error', false],
    'warning does not see an info' => ['warning', false],
    'info exits non-zero' => ['info', true],
    'hint exits non-zero (info is louder)' => ['hint', true],
]);
