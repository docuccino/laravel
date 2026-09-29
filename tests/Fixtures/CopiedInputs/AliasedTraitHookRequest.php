<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Takes its hook from a trait method under another name, so the hook is that method's body. */
final class AliasedTraitHookRequest extends FormRequest
{
    use CopiesTraceId {
        copyTrace as protected prepareForValidation;
    }
}
