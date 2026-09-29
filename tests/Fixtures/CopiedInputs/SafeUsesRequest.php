<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/** A copy beside every use of `$this` that keeps the request where it is: reads, class-name uses, `isset()`. */
final class SafeUsesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);

        $this->merge([
            'slug' => Str::slug((string) $this->title),
            'titled' => isset($this->title) || empty($this->subtitle),
            'kind' => $this::class,
            'form' => $this instanceof FormRequest,
            'first' => $this->query->all()['page'] ?? null,
            'agent' => strtolower((string) $this->headers->get('User-Agent')),
        ]);
    }
}
