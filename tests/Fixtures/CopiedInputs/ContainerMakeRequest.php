<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpFoundation\Request;

/** Merges through `app()->make()` of the Symfony request, which the container aliases to the same one. */
final class ContainerMakeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
        app()->make(Request::class)->merge(['key' => 'overwritten']);
    }
}
