<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Two traits offer a hook, and `insteadof` takes the attributed one, listed second. */
final class AttributedChosenTraitHookRequest extends FormRequest
{
    use CopiesIdempotencyKey, CopiesRegion {
        CopiesRegion::prepareForValidation insteadof CopiesIdempotencyKey;
    }
}
