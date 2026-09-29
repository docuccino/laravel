<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Takes an attributed hook from a trait. */
final class AttributedTraitHookRequest extends FormRequest
{
    use CopiesRegion;
}
