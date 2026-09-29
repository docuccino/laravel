<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedRules;

use Illuminate\Foundation\Http\FormRequest;

/** A body tagged by its own `channel`: the whole request is one shape per channel. */
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
            'channel' => 'required|in:email,sms',
            'address' => 'exclude_unless:channel,email|required|email',
            'phone' => 'exclude_unless:channel,sms|required|string|max:20',
            'body' => 'required|string|max:500',
        ];
    }
}
