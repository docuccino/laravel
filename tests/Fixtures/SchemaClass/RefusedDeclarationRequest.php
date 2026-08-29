<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SchemaClass;

use Docuccino\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request type whose declaration nests under a field the rules proved is a string, so the body cannot
 * carry it and the refusal names the type it was written on. Only ever reflected.
 */
#[BodyParameter(name: 'nickname.locale', type: 'string')]
final class RefusedDeclarationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['nickname' => 'required|string'];
    }
}
