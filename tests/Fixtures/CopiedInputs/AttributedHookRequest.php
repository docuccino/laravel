<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Its hook carries an attribute on the line above, where reflection and the parser place it apart. */
final class AttributedHookRequest extends FormRequest
{
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->header('Idempotency-Key')]);
    }
}
