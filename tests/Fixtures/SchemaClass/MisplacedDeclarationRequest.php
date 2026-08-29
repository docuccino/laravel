<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SchemaClass;

use Docuccino\Attributes\QueryParameter;
use Docuccino\Attributes\Summary;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request type carrying two declarations nothing reads on a type — the population
 * `attribute.schema-class-unread` names. Only ever reflected.
 */
#[Summary('Update the tenant')]
#[QueryParameter(name: 'page')]
#[QueryParameter(name: 'per_page')]
final class MisplacedDeclarationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['nickname' => 'required|string'];
    }
}
