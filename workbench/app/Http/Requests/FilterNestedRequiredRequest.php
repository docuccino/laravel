<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A workbench FormRequest whose only requirement is two levels down, with no required member beside it
 * at the top of the container — the shape a required depth-1 sibling would otherwise cover for.
 */
final class FilterNestedRequiredRequest extends FormRequest
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
            'filter.window.from' => 'required|date',
            'filter.window.to' => 'date',
        ];
    }
}
