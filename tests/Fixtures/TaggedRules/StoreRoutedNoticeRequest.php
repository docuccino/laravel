<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedRules;

use Illuminate\Foundation\Http\FormRequest;

/**
 * {@see StoreNoticeRequest}'s rules, with the channel and the phone number copied from headers: the tag the
 * body would be split by, and a member it switches, are not the body's to send.
 */
final class StoreRoutedNoticeRequest extends FormRequest
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
        $this->merge(['channel' => $this->header('X-Channel')]);
    }
}
