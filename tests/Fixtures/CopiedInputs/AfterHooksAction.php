<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Validation\Validator;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/** Adds a check to the validator the package built, over the data it was built with. */
final class AfterHooksAction
{
    use AsAction;

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function withValidator(Validator $validator, ActionRequest $request): void
    {
        $validator->after(function () use ($validator, $request): void {
            if ($request->header('Idempotency-Key') === 'reused') {
                $validator->errors()->add('key', 'The key has been used.');
            }
        });
    }

    public function prepareForValidation(ActionRequest $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);
    }

    public function handle(): void {}
}
