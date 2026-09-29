<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** The copy is followed by a call into the request's own code, which may write anything. */
final class HelperCallRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
        $this->normalise();
    }

    private function normalise(): void
    {
        $this->merge(['key' => 'overwritten']);
    }
}
