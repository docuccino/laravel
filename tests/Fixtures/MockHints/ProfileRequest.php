<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MockHints;

use Docuccino\Attributes\Mock;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A FormRequest whose fields exist only as rule keys, so the class-level `#[Mock]` form is the only
 * one that can name them.
 */
#[Mock(faker: 'safeEmail', property: 'email')]
#[Mock(faker: 'numberBetween:1,100', seedGroup: 'listing', property: 'per_page')]
#[Mock(faker: 'word', property: 'gone')]
final class ProfileRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'per_page' => 'required|integer',
        ];
    }
}
