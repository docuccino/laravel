<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests\Failures;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Hides a report from someone it does not authorize, answering as though there were none. */
final class HiddenRefusalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-reports') ?? false;
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
        throw new HttpResponseException(response()->json(['message' => 'Not found.'], 404));
    }
}
