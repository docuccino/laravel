<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedRules;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A nullable object tagged by `delivery.method`, written the way a FormRequest writes a tagged union: every
 * conditional member is switched off by an exclude rule on the tag, with literal values. One member is
 * `present` rather than `required`, one is excluded for a value rather than kept for one, one is on every
 * branch, and one enum case is named by no exclude rule at all.
 */
final class StoreShipmentRequest extends FormRequest
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
            'delivery' => ['present', 'nullable', 'array'],
            'delivery.method' => ['required_with:delivery', Rule::enum(DeliveryMethod::class)],
            'delivery.reference' => ['required_with:delivery', 'string', 'max:40'],
            'delivery.address' => ['exclude_unless:delivery.method,courier,locker', 'required', 'string'],
            'delivery.slots' => ['exclude_unless:delivery.method,locker', 'present', 'array', 'max:20'],
            'delivery.slots.*' => ['integer'],
            'delivery.contacts' => ['exclude_unless:delivery.method,pickup', 'required', 'array', 'min:1', 'max:20'],
            'delivery.contacts.*' => ['string', 'max:8'],
            'delivery.instructions' => ['exclude_if:delivery.method,pickup,digital', 'string', 'max:200'],
            'priority' => ['required', 'boolean'],
        ];
    }
}
