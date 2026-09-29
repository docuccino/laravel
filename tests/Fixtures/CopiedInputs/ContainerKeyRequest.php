<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Merges through `app('request')`, the request it was built from. */
final class ContainerKeyRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
        app('request')->merge(['key' => 'overwritten']);
    }
}
