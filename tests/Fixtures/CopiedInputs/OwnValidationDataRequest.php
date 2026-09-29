<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Validates what its own `validationData()` returns, which need not be the merged input. */
final class OwnValidationDataRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->only('note');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
    }
}
