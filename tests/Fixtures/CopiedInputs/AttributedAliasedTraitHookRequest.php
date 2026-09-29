<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Takes an attributed trait method as its hook under an alias. */
final class AttributedAliasedTraitHookRequest extends FormRequest
{
    use CopiesTenant {
        copyTenant as protected prepareForValidation;
    }
}
