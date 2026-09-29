<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/** Every spelling of a copy the reader trusts, through the request the package hands the hook, under the name the hook gives it. */
final class CopyingAction
{
    use AsAction;

    private string $prefix = '';

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function prepareForValidation(Repository $config, ActionRequest $incoming): void
    {
        // Its own state is not the request's.
        $this->prefix = 'x';

        $incoming->merge([
            'key' => $incoming->header('Idempotency-Key'),
            'trace' => $incoming->headers->get('X-Trace-Id'),
            'page' => $incoming->query('page'),
            'post' => $incoming->route('post'),
            'defaulted' => $incoming->header('X-Locale', 'en'),
            'slug' => Str::slug((string) $incoming->input('title')),
            'locale' => $config->get('app.locale'),
        ]);
    }

    public function handle(): void {}
}
