<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedUnion;

use Docuccino\Attributes\BodyParameter;

/** Takes a tag, and sends one back. */
final class TagController
{
    #[BodyParameter(name: 'tag', type: Tag::class)]
    public function store(): void {}

    public function show(): Tag
    {
        return new Tag('docs', new LabelData('Docs'));
    }
}
