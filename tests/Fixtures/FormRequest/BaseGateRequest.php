<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FormRequest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One authorization rule written for every request under it. The framework declares no `authorize()`
 * of its own — `passesAuthorization()` calls one only where `method_exists` — so a gate found on a
 * base is somebody's, and it is the gate that runs. Only ever reflected / analysed.
 */
abstract class BaseGateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
