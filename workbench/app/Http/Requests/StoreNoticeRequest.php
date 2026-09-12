<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Docuccino\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * For each of the three things a rules recovery can fail to read — a closure rule, an `in` whose values
 * come out of a method, a bare `array` with no key or item rules beside it — one field this class
 * documents about itself and one it says nothing about. Only ever reflected; its trace is scripted where
 * the document is built.
 */
#[BodyParameter(name: 'attachment', type: 'object')]
#[BodyParameter(name: 'status', type: 'string')]
#[BodyParameter(name: 'tags', type: 'string[]')]
final class StoreNoticeRequest extends FormRequest
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
            'attachment' => [static fn (): bool => true],
            'secret' => [static fn (): bool => true],
            'status' => ['required', Rule::in('any', ...$this->tiers())],
            'visibility' => ['required', Rule::in('draft', ...$this->tiers())],
            'tags' => ['array'],
            'meta' => ['array'],
        ];
    }

    /**
     * @return list<string>
     */
    private function tiers(): array
    {
        return ['bronze', 'silver'];
    }
}
