<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Two traits offer a hook, and it takes one of them. */
final class ChosenTraitHookRequest extends FormRequest
{
    use CopiesIdempotencyKey, CopiesLocale {
        CopiesLocale::prepareForValidation insteadof CopiesIdempotencyKey;
    }
}
