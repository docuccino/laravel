<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedRules;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A tagged `delivery` object beside a key the request copies from a header, which the body gives up. */
final class StoreTrackedShipmentRequest extends FormRequest
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
            'delivery.address' => ['exclude_unless:delivery.method,courier,locker', 'required', 'string'],
            'tracking_key' => ['required', 'string', 'max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['tracking_key' => $this->header('X-Tracking-Key')]);
    }
}
