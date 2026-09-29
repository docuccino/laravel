<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Calls into its own code through `$this::`. */
final class OwnStaticCallRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
        $this::normalise();
    }

    private static function normalise(): void
    {
        app('request')->merge(['key' => 'overwritten']);
    }
}
