<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** A merge that also spreads keys this cannot name, one of which may be the copied key. */
final class SpreadMergeRequest extends FormRequest
{
    /** @var array<string, mixed> */
    private array $defaults = [];

    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
        $this->merge([...$this->defaults]);
    }
}
