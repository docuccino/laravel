<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests\Failures;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/** Refuses a request it does not authorize with a message of its own. */
final class RefusalMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('file-reports') ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['title' => ['required', 'string']];
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('You may not file reports.');
    }
}
