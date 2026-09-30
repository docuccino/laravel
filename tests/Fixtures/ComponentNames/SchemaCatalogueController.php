<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames;

use Docuccino\Attributes\Example;

/**
 * An endpoint whose payload IS a schema document, the way a schema registry or a form builder serves
 * one. Its example carries a JSON Reference that happens to spell a component of this very document —
 * which makes it no less a value the server sends.
 */
final class SchemaCatalogueController
{
    #[Example(value: ['name' => 'user', 'schema' => ['$ref' => '#/components/schemas/UserData']])]
    public function show(): array
    {
        return [];
    }
}
