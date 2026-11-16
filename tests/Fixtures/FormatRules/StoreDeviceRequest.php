<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FormatRules;

use Illuminate\Foundation\Http\FormRequest;

/** String rules Laravel checks against a fixed grammar: the address families, a MAC address and a hex colour. */
final class StoreDeviceRequest extends FormRequest
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
            'address' => ['required', 'ip'],
            'ipv4' => ['required', 'ipv4'],
            'ipv6' => ['required', 'ipv6'],
            'gateway' => 'nullable|string|ipv4',
            'mac' => ['required', 'mac_address'],
            'colour' => 'nullable|hex_color',
        ];
    }
}
