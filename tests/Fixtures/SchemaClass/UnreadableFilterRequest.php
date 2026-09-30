<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SchemaClass;

use Docuccino\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request type whose one `#[BodyParameter]` PHP cannot construct — an `int` where the attribute takes a
 * `string`. Bound to read routes, write routes or both, so the report is seen to be the same one at every
 * verb. Only ever reflected.
 */
/* @phpstan-ignore argument.type (the wrong argument type IS the fixture) */
#[BodyParameter(name: 123)]
final class UnreadableFilterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['nickname' => 'required|string'];
    }
}
