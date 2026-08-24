<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A workbench FormRequest on a READ verb, so its rules land as query parameters rather than a body —
 * which is what `#[IgnoreParam]` has to be able to subtract from.
 */
final class SearchFormsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:100',
            'trace_id' => 'nullable|string',
        ];
    }
}
