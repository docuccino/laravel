<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SchemaClass;

use Docuccino\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request type bound to a read route AND a write route: the declaration is load-bearing on the write
 * one, so nothing may be said about it on the read one. Only ever reflected.
 */
#[BodyParameter(name: 'overrides', type: 'object', description: 'Arbitrary per-tenant overrides.')]
final class SharedPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['nickname' => 'required|string'];
    }
}
