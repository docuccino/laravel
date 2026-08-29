<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SchemaClass;

use Docuccino\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request type bound only to read routes, where its rules become query parameters and a declaration
 * about a body reaches nothing — the population `attribute.schema-class-unusable` names. Only ever
 * reflected.
 */
#[BodyParameter(name: 'filters', type: 'object', description: 'Arbitrary filters.')]
final class ReadOnlyFilterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['nickname' => 'required|string'];
    }
}
