<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests\Failures;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** Fails a validation with whichever exception the application is configured to throw. */
final class ConfiguredFailureRequest extends FormRequest
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
        $exception = config('api.validation_exception');

        throw new $exception($validator);
    }
}
