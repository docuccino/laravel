<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ExcludedFields;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\ExcludeUnless;

/**
 * A body tagged by `payment.method`, validated the way a FormRequest writes a tagged union: each branch's
 * fields carry an exclude rule keyed on the tag, ahead of their `required`. Beside it, one field of every
 * other exclude spelling, and fields whose `required` comes BEFORE the exclude rule.
 */
final class StorePaymentRequest extends FormRequest
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
            'payment' => ['required', 'array'],
            'payment.method' => ['required', 'in:card,transfer'],
            'payment.card_number' => ['exclude_unless:payment.method,card', 'required', 'string'],
            'payment.iban' => ['exclude_unless:payment.method,transfer', 'required', 'string'],
            'reference' => ['required', 'exclude_if:payment.method,transfer', 'string'],
            'note' => 'exclude_if:payment.method,card|required|string|max:200',
            'gift_wrap' => ['exclude_with:voucher', 'required', 'boolean'],
            'delivery_date' => ['exclude_without:address,postcode', 'required', 'date'],
            'legacy_token' => ['exclude', 'required', 'string'],
            'coupon' => [Rule::excludeIf(fn (): bool => ! $this->has('basket')), 'required', 'string'],
            'voucher' => [new ExcludeUnless(fn (): bool => $this->has('basket')), 'present', 'string'],
            'channel' => ['present', 'exclude_unless:payment.method,card', 'string'],
        ];
    }
}
