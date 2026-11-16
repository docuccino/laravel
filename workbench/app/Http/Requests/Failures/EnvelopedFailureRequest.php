<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests\Failures;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Answers a failed validation with an envelope of its own, the validator's errors under `errors`. */
final class EnvelopedFailureRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['title' => ['required', 'string']];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
