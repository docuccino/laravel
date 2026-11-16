<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests\Failures;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

/** Records a failed validation, then fails it as the framework does. */
final class ParentFailureRequest extends FormRequest
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
        Log::info('Submission refused.', ['errors' => $validator->errors()->toArray()]);

        parent::failedValidation($validator);
    }
}
