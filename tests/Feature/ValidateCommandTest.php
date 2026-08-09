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
