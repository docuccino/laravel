<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** A return ahead of the merge skips it on some requests. */
final class EarlyReturnRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->isMethod('GET')) {
            return;
        }

        $this->merge(['key' => $this->header('Idempotency-Key')]);
    }
}
