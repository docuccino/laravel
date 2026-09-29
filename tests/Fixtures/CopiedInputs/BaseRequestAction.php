<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/** Merges into the framework's request, which is not the one the package validates. */
final class BaseRequestAction
{
    use AsAction;

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function prepareForValidation(Request $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);
    }

    public function handle(): void {}
}
