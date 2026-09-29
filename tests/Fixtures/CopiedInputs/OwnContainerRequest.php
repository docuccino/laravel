<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Merges through the container the request was handed, which resolves the request it was built from. */
final class OwnContainerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
        $this->container->make('request')->merge(['key' => 'overwritten']);
    }
}
