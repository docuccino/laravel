<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A FormRequest that reads request headers in each place one can: the gate, a method the framework looks
 * for by name, a hook it overrides, and a method the action itself calls.
 */
final class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->header('X-Service-Token') === config('services.orders.token')
            || $this->hasHeader('Idempotency-Key');
    }

    public function withValidator(object $validator): void
    {
        $this->merge(['locale' => $this->header('X-Locale')]);
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return ['sku' => 'required|string'];
    }

    public function idempotencyKey(): ?string
    {
        return $this->header('Idempotency-Key');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['client' => $this->headers->get('X-Client-Version')]);
    }
}
