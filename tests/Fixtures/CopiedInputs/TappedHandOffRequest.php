<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Hands itself to a function — here `tap()`, whose callback merges under another name. */
final class TappedHandOffRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
        tap($this, static fn (FormRequest $request) => $request->merge(['key' => 'overwritten']));
    }
}
