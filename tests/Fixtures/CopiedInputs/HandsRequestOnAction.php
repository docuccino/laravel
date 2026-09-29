<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/** Hands the request to its own code, which may merge over the copy. */
final class HandsRequestOnAction
{
    use AsAction;

    private function normalise(ActionRequest $request): void
    {
        $request->merge(['key' => 'overwritten']);
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function prepareForValidation(ActionRequest $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);
        $this->normalise($request);
    }

    public function handle(): void {}
}
