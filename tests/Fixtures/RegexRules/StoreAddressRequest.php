<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RegexRules;

use Illuminate\Foundation\Http\FormRequest;

/** `regex:` rules as applications write them: case-insensitive, and Unicode letters and spaces under `/u`. */
final class StoreAddressRequest extends FormRequest
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
            'postcode' => ['required', 'string', 'regex:/^[A-Z]{1,2}[0-9][A-Z0-9]? ?[0-9][A-Z]{2}$/iD'],
            'colour' => 'nullable|regex:/^#(?:[0-9a-f]{3}){1,2}$/i',
            'unit' => ['required', 'string', 'regex:/^[a-z]+$/iuD'],
            'recipient' => ['required', 'string', 'regex:/^[\pL\s\-]+$/uD'],
            'initials' => ['required', 'string', 'regex:/^\p{Lu}+$/iuD'],
            'full_name' => ['required', 'string', 'regex:/^[\pL\'-]+(?:\s+[\pL\'-]+)*$/uD'],
        ];
    }
}
