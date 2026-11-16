<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ArrayChoices;

use Docuccino\Laravel\Tests\Fixtures\TypedChoices\Grade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Choice lists beside an `array` rule, alone and beside item rules, and one beside `list` instead.
 */
final class StoreChoiceListRequest extends FormRequest
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
            'tags' => ['sometimes', 'array', 'in:news,sport'],
            'codes' => ['sometimes', 'array', Rule::in(['1', '2'])],
            'codes.*' => ['string', 'distinct'],
            'grades' => ['sometimes', Rule::array(), Rule::in([Grade::Pass, Grade::Merit, Grade::Distinction])],
            'grades.*' => ['integer'],
            'muted' => ['sometimes', 'nullable', 'array', 'not_in:spam,ads'],
            'ids' => ['sometimes', 'array', Rule::notIn([1, 2])],
            'ids.*' => ['integer'],
            'listed' => ['sometimes', 'list', 'in:a,b'],
        ];
    }
}
