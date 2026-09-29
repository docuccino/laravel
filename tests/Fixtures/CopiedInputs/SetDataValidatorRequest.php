<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Points the validator at the query string once it is built. */
final class SetDataValidatorRequest extends FormRequest
{
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->setData($this->query());
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
    }
}
