<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

/** Merges through `app(Request::class)`, which the container aliases to the request it was built from. */
final class ContainerClassRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
        app(Request::class)->merge(['key' => 'overwritten']);
    }
}
