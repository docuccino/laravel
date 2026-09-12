<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A workbench FormRequest that requires one member of a filter surface and leaves its sibling optional.
 * The rules land a phase after the parameter attributes, so this is the second producer to answer for
 * the container's `required` list.
 */
final class FilterRequiredMemberRequest extends FormRequest
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
            'filter.min_days' => 'required|integer',
            'filter.status' => 'string',
        ];
    }
}
