<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\EnumCaseRules;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;

/**
 * A body whose choice rules name enum cases rather than values: string-backed, int-backed and pure, in a
 * subset, in full, beside a plain value, excluded, and as an enum rule's selection.
 */
final class StorePostRequest extends FormRequest
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
            'status' => ['required', Rule::in([PostStatus::Draft, PostStatus::Published])],
            'state' => ['sometimes', new In([PostStatus::Draft, PostStatus::Published, PostStatus::Archived])],
            'phase' => ['sometimes', 'string', Rule::notIn([PostStatus::Archived])],
            'stage' => ['sometimes', 'string', new NotIn([PostStatus::Archived])],
            'priority' => ['sometimes', Rule::in(PostPriority::Low, PostPriority::High)],
            'visibility' => ['sometimes', Rule::in([PostVisibility::Public])],
            'schedule' => ['sometimes', Rule::in([PostStatus::Draft, 'scheduled'])],
            'review' => ['sometimes', Rule::enum(PostStatus::class)->only([PostStatus::Draft, PostStatus::Published])],
            'urgency' => ['sometimes', Rule::enum(PostPriority::class)->except(PostPriority::Low)],
        ];
    }
}
