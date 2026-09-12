<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A workbench FormRequest that bounds a filter surface the application itself requires, and one whose
 * value is an object of its own — the two facts a deepObject container carries that a flat parameter
 * cannot: a member on the container's `required` list, and a member nested below another.
 */
final class FilterWindowRequest extends FormRequest
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
            'filter.opaque' => 'required|string|max:40',
            'filter.window.from' => 'date',
            'filter.window.to' => 'date',
        ];
    }
}
