<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Http\Request;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/** Holds a second handle on the input beside the package's request. */
final class TwoRequestsAction
{
    use AsAction;

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function prepareForValidation(ActionRequest $request, Request $base): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);
        $base->merge(['key' => 'overwritten']);
    }

    public function handle(): void {}
}
