<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FormRequest;

use Closure;
use Docuccino\Attributes\BodyParameter;

/**
 * The type-level declaration site: a request class that documents one of its own fields with
 * `#[BodyParameter]` while both of its `rules()` entries are closures nothing can read. Only ever
 * reflected; its trace is scripted in the test.
 */
#[BodyParameter(name: 'file', type: 'string', format: 'binary')]
final class DeclaredRulesData
{
    /**
     * @return array<string, list<Closure>>
     */
    public function rules(): array
    {
        return [
            'file' => [static fn (): bool => true],
            'secret' => [static fn (): bool => true],
        ];
    }
}
