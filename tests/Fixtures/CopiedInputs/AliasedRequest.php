<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Merges again through a variable holding itself. */
final class AliasedRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
        $request = $this;
        $request->merge(['key' => 'overwritten']);
    }
}
