<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as Validation;

/** Replaces the validator the framework builds with one over the JSON body alone. */
final class ValidatorInstanceRequest extends FormRequest
{
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    protected function getValidatorInstance(): Validator
    {
        return $this->validator ??= Validation::make($this->json()->all(), $this->rules());
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
    }
}
