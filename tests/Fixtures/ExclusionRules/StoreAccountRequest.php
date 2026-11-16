<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ExclusionRules;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Rules that only exclude values, on fields no other rule types, and beside a type rule that does. */
final class StoreAccountRequest extends FormRequest
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
            'username' => ['required', 'string', 'max:32'],
            'role' => ['sometimes', 'not_in:admin,root'],
            'tier' => ['sometimes', Rule::notIn([1, 2])],
            'handle' => ['sometimes', 'string', Rule::notIn(['admin', 'root'])],
            'level' => ['sometimes', 'integer', 'not_in:1,2'],
            'alias' => ['sometimes', 'different:username'],
            'nickname' => ['sometimes', 'not_regex:/^admin/'],
            'prefix' => ['sometimes', 'doesnt_start_with:sys'],
            'suffix' => ['sometimes', 'doesnt_end_with:bot'],
            'legacy_id' => ['prohibited'],
            'referrer' => ['prohibited_if:role,guest'],
        ];
    }
}
