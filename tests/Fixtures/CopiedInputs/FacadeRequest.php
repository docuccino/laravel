<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Request;

/** Merges through the request facade, the request it was built from. */
final class FacadeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
        Request::merge(['key' => 'overwritten']);
    }
}
