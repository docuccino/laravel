<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedRules;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The tagged object of {@see StoreShipmentRequest}, with a member gated on a second field of it as well — so
 * no single field selects which members a request carries.
 */
final class StoreMixedShipmentRequest extends FormRequest
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
            'delivery.mode' => ['nullable', 'in:express,standard'],
            'delivery.address' => ['exclude_unless:delivery.method,courier,locker', 'required', 'string'],
            'delivery.tracking' => ['exclude_unless:delivery.mode,express', 'required', 'string'],
        ];
    }
}
