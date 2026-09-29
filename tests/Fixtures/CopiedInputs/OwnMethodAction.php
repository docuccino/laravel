<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/** Calls its own code, which can reach the request without being handed it. */
final class OwnMethodAction
{
    use AsAction;

    private function touch(): void
    {
        app(ActionRequest::class)->merge(['key' => 'overwritten']);
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function prepareForValidation(ActionRequest $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);
        $this->touch();
    }

    public function handle(): void {}
}
