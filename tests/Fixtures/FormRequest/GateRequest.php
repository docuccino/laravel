<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FormRequest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A FormRequest declaring an authorize() gate in its own file — the implicit-403 signal reads its
 * return type from the engine (a literal `true` produces no 403; anything else can deny). Only ever
 * reflected / analysed.
 */
final class GateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
