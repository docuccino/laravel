<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SchemaClass;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request type bound only to read routes, where its rules become query parameters and a declaration
 * about a body reaches nothing — the population `attribute.schema-class-unusable` names. Only ever
 * reflected.
 *
 * The `#[SchemaName]` beside it is the other declaration a read-only-bound type loses: no component is
 * minted for the type here, so the name it asked for is dropped, and unlike the `#[BodyParameter]`
 * above nothing reports that. Pinned rather than fixed, for the reason recorded on
 * `SchemaClassAttributes::CONDITIONAL`.
 */
#[SchemaName('PreferenceFilters')]
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
