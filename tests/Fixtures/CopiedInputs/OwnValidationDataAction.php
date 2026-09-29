<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/** Hands the validator its own data rather than the merged input. */
final class OwnValidationDataAction
{
    use AsAction;

    /** @return array<string, mixed> */
    public function getValidationData(ActionRequest $request): array
    {
        return $request->only('note');
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function prepareForValidation(ActionRequest $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);
    }

    public function handle(): void {}
}
