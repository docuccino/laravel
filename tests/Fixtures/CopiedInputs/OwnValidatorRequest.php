<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** Builds its own validator, from whatever data it likes — here, not the merged input. */
final class OwnValidatorRequest extends FormRequest
{
    public function validator(Factory $factory): Validator
    {
        return $factory->make($this->only('note'), ['key' => 'required|uuid']);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
    }
}
