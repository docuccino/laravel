<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\AdoptedUnion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An answer partitioned by its kind, written the way a FormRequest writes a tagged union, and a note bounded by its rules. */
class AnswerRequest extends FormRequest
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
            'answer' => ['present', 'nullable', 'array'],
            'answer.kind' => ['required_with:answer', Rule::enum(ShapeKind::class)],
            'answer.value' => ['exclude_unless:answer.kind,count,measure', 'required', 'numeric'],
            'answer.steps' => ['exclude_unless:answer.kind,measure', 'present', 'array', 'max:20'],
            'answer.steps.*' => ['integer'],
            'answer.letters' => ['exclude_unless:answer.kind,letters', 'required', 'array', 'min:1', 'max:20'],
            'answer.letters.*' => ['string', 'max:8'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }
}
