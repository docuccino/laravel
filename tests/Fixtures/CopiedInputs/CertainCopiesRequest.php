<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/** Every spelling of a copy the reader trusts, beside values that copy nothing it can name. */
final class CertainCopiesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $name = 'X-Dynamic';

        $this->merge([
            'key' => $this->header('Idempotency-Key'),
            'trace' => $this->headers->get('X-Trace-Id'),
            'page' => $this->query('page'),
            'post' => $this->route('post'),
            'defaulted' => $this->header('X-Locale', 'en'),
            'dynamic' => $this->header($name),
            'nested' => $this->query('filter.status'),
            'elsewhere' => request()->header('X-Elsewhere'),
            'renamed' => $this->input('title'),
            'lowered' => strtolower((string) $this->header('X-Case')),
            // An input value read through `__get()` and handed to a function is a value, not the request.
            'slug' => Str::slug((string) $this->title),
        ]);
    }
}
