<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FormRequest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A FormRequest that declares NO authorize() gate of its own — the implicit-403 signal must not fire
 * for it (there is no own-file gate to prove can-deny). Only ever reflected / analysed.
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
