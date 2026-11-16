<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests\Failures;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/** Answers a failed validation with messages of its own, in a named bag and with a redirect for a browser. */
final class BaggedFailureRequest extends FormRequest
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
        throw ValidationException::withMessages([
            'submission' => ['The submission could not be accepted.'],
        ])->errorBag('submission')->redirectTo('/submissions');
    }
}
