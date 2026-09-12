<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Workbench\App\Enums\WidgetStatus;

/**
 * A workbench FormRequest whose rules the validation integration recovers statically (its `rules()`
 * array is analysed as a constant shape — never executed) and whose `status` rule names an enum class,
 * which is the descriptor path: the same enum a response property carries, so the document holds ONE
 * component both directions reference. `role` states its values inline and has no class to be named
 * after.
 */
final class StoreWidgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'quantity' => 'required|integer|min:1',
            'avatar' => 'nullable|image',
            'role' => 'required|in:admin,user',
            'status' => [Rule::enum(WidgetStatus::class)],
        ];
    }
}
