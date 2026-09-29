<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Foundation\Http\FormRequest;

/** Takes its hook from a trait. */
final class TraitHookRequest extends FormRequest
{
    use CopiesIdempotencyKey;
}
