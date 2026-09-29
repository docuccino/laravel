<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Hands itself to a static helper after the copy. */
final class StaticHandOffRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
        InputNormaliser::apply($this);
    }
}
