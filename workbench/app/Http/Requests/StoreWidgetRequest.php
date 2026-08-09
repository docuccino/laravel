<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A workbench FormRequest whose rules the validation integration recovers statically (its `rules()`
 * array is analysed as a constant shape — never executed).
 */
final class StoreWidgetRequest extends FormRequest
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
            'name' => 'required|string|max:100',
            'quantity' => 'required|integer|min:1',
            'avatar' => 'nullable|image',
            'role' => 'required|in:admin,user',
        ];
    }
}
