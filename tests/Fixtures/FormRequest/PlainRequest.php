<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FormRequest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A FormRequest with no authorize() anywhere in its hierarchy — the framework declares none either,
 * so nothing gates it and the implicit-403 signal must not fire. Only ever reflected / analysed.
 */
final class PlainRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['name' => 'required|string'];
    }
}
