<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TypedChoices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Choice lists beside a type rule that types the field, and one with no type rule beside it; and lists written
 * as Laravel parses them, with an empty entry and a quoted one holding a comma.
 */
final class StoreChoiceRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'in:1,2'],
            'count' => ['sometimes', 'integer', 'in:1,2,many'],
            'ratio' => ['sometimes', 'numeric', 'in:0.5,1'],
            'consent' => ['sometimes', 'boolean', Rule::in([true, false])],
            'opt_in' => ['sometimes', 'boolean', 'in:0,1'],
            'grade' => ['sometimes', 'string', Rule::enum(Grade::class)],
            'rank' => ['sometimes', 'integer', Rule::enum(Grade::class)],
            'blocked' => ['sometimes', 'string', Rule::notIn([1, 2])],
            'tier' => ['sometimes', 'in:1,2'],
            'none' => ['sometimes', 'integer', 'in:a,b'],
            'either' => ['sometimes', 'boolean', 'in:1,'],
            'quoted' => ['sometimes', 'string', 'in:"x,y",z'],
        ];
    }
}
