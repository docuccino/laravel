<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DescribedNotes;

use Docuccino\Laravel\Tests\Fixtures\EnumDescription\ReviewStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fields whose rules each state something no keyword can: a date bound, a set of prefixes, a condition
 * that requires one and one that refuses another, and an enum that describes itself.
 */
final class ListingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'until' => ['sometimes', 'date', 'after:from'],
            'name' => ['sometimes', 'string', 'starts_with:ab,cd'],
            'stage' => ['required_with:name', Rule::enum(ReviewStage::class)],
            'kind' => ['sometimes', 'string'],
            'nickname' => ['prohibited_if:kind,business', 'string'],
        ];
    }
}
