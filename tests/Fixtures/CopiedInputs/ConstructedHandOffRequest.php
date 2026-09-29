<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Hands itself to an object it constructs. */
final class ConstructedHandOffRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
        (new InputNormaliser($this))->run();
    }
}
