<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/** Merges into a service the container resolves that is not the request, which leaves its input alone. */
final class OtherServiceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
        app(Collection::class)->merge(['key' => 'overwritten']);
    }
}
