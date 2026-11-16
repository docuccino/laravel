<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DescribedNotes;

use Docuccino\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

/** A date bound the request TYPE describes, for every operation that accepts it. */
#[BodyParameter('until', description: 'End of the range.')]
final class TypeDescribedListingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'until' => ['sometimes', 'date', 'after:from'],
        ];
    }
}
