<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Tags;

use Docuccino\Core\Extensions\Contracts\TagMapper;

/**
 * A tag mapper whose answer is a constructor argument rather than anything in its own file — the shape a
 * `tags.mapper` naming a container binding takes, and one whose files never move between two answers.
 */
final class StatefulTagMapper implements TagMapper
{
    public function __construct(private readonly string $prefix) {}

    public function map(string $tag): string
    {
        return $this->prefix.'-'.$tag;
    }
}
