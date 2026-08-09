<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\LaravelActions;

use Lorisleiva\Actions\Concerns\AsAction;

/**
 * A minimal laravel-actions action: only `handle()`, no `asController()`, `rules()` or `authorize()`.
 * Resolves to `handle()` and contributes no request body or 403 — the degradation baseline.
 */
final class SimpleAction
{
    use AsAction;

    public function handle(): bool
    {
        return true;
    }
}
