<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\AdoptedUnion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An answer whose `count` also needs a reason no answer class has, beside a level and a ratio the action declares. */
final class ExtendedAnswerRequest extends FormRequest
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
            'answer' => ['required', 'array'],
            'answer.kind' => ['required', Rule::enum(ShapeKind::class)],
            'answer.value' => ['exclude_unless:answer.kind,count,measure', 'required', 'numeric'],
            'answer.reason' => ['exclude_unless:answer.kind,count', 'required', 'string'],
            'answer.steps' => ['exclude_unless:answer.kind,measure', 'present', 'array', 'max:20'],
            'answer.steps.*' => ['integer'],
            'answer.letters' => ['exclude_unless:answer.kind,letters', 'required', 'array', 'min:1', 'max:20'],
            'answer.letters.*' => ['string', 'max:8'],
            'level' => ['required', 'string', 'in:low,high'],
            'ratio' => ['required', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
