<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** Builds the default validator over the query string, not the merged input. */
final class DefaultValidatorRequest extends FormRequest
{
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    protected function createDefaultValidator(Factory $factory): Validator
    {
        return $factory->make($this->query(), $this->rules());
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
    }
}
