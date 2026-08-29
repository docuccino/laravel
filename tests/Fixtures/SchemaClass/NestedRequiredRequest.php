<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SchemaClass;

use Docuccino\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A type whose rules make nothing required at the top level, and whose own declaration makes a field
 * deep inside the body required — so the body itself is one the server insists on. Only ever reflected.
 */
#[BodyParameter(name: 'meta.token', type: 'string', required: true)]
final class NestedRequiredRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['nickname' => 'string'];
    }
}
