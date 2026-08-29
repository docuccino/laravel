<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SchemaClass;

use Docuccino\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request type that documents a free-form map on ITSELF: `overrides` is a container whose keys no
 * validation rule can enumerate, and that is a fact about the type rather than about one endpoint.
 * Only ever reflected.
 */
#[BodyParameter(name: 'overrides', type: 'object', description: 'Arbitrary per-tenant overrides.')]
#[BodyParameter(name: 'overrides.locale', type: 'string')]
final class PreferencesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nickname' => 'required|string',
            'overrides' => 'array',
        ];
    }
}
