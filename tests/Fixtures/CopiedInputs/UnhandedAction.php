<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Lorisleiva\Actions\Concerns\AsAction;

/** Reaches the request only through the container, so there is no request of its own to read. */
final class UnhandedAction
{
    use AsAction;

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function prepareForValidation(): void
    {
        request()->merge(['key' => request()->header('Idempotency-Key')]);
    }

    public function handle(): void {}
}
