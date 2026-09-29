<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/** Builds its own validator, from whatever data it likes. */
final class OwnValidatorAction
{
    use AsAction;

    public function getValidator(Factory $factory, ActionRequest $request): Validator
    {
        return $factory->make($request->only('note'), $this->rules());
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
