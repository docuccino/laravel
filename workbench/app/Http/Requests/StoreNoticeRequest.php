<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Closure;
use Docuccino\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Workbench\App\Rules\NoticeAuthor;
use Workbench\App\Rules\NoticeFilter;

/**
 * For each of the things a rules recovery can fail to read — a closure rule, an `in` whose values come
 * out of a method, a bare `array` with no key or item rules beside it, a rule object beside rules that
 * read — one field this class documents about itself and one it says nothing about. Beside them, a rule
 * object annotated as stating nothing, a closure beside a rule that reads, and a conditional rule, which
 * gives up its field. Only ever reflected; its trace is scripted where the document is built.
 */
#[BodyParameter(name: 'attachment', type: 'object')]
#[BodyParameter(name: 'channel', type: 'string')]
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
            'channel' => ['string', new NoticeFilter],
            'reference' => ['string', new NoticeFilter],
            'author' => ['integer', new NoticeAuthor],
            'body' => ['string', static fn (string $attribute, mixed $value, Closure $fail): bool => true],
            'audience' => ['string', Rule::when($this->isMethod('PATCH'), ['nullable'])],
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
