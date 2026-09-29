<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\AlphaRules;

use Illuminate\Foundation\Http\FormRequest;

/** The `alpha` family in both of its forms — any script, and `:ascii` — spelled as arrays and as pipe strings. */
final class StoreSlugRequest extends FormRequest
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
            'alpha' => ['required', 'string', 'alpha'],
            'alpha_ascii' => ['required', 'string', 'alpha:ascii'],
            'alpha_num' => ['required', 'string', 'alpha_num'],
            'alpha_num_ascii' => ['required', 'string', 'alpha_num:ascii'],
            'alpha_dash' => ['required', 'string', 'alpha_dash'],
            'alpha_dash_ascii' => ['required', 'string', 'alpha_dash:ascii'],
            'handle' => 'nullable|alpha_dash|max:32',
            'handle_ascii' => 'nullable|alpha_dash:ascii|max:32',
        ];
    }
}
