<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

/** Runs its own resolution, which validates the query string and never calls the hook. */
final class OwnValidateResolvedRequest extends FormRequest
{
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function validateResolved(): void
    {
        Validator::make($this->query(), $this->rules())->validate();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
    }
}
