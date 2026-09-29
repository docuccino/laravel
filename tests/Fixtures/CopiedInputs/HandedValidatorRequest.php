<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Hands the built validator to code of its own, which may give it other data. */
final class HandedValidatorRequest extends FormRequest
{
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function withValidator(Validator $validator): void
    {
        $this->scope($validator);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
    }

    private function scope(Validator $validator): void
    {
        $validator->setData($this->query());
    }
}
