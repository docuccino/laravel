<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedRules;

use Illuminate\Foundation\Http\FormRequest;

/** {@see StoreNoticeRequest}'s rules, with the phone number a channel switches on copied from a header. */
final class StoreDialledNoticeRequest extends FormRequest
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

    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => $this->header('X-Phone')]);
    }
}
