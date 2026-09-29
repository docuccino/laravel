<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Merges through `request()`, the request it was built from, whose JSON bag it shares. */
final class GlobalRequestRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
        request()->merge(['key' => 'overwritten']);
    }
}
