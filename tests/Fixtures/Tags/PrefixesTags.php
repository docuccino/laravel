<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Tags;

/** The half of a tag mapper's answer that a trait can hold. */
trait PrefixesTags
{
    abstract protected function prefix(): string;

    public function map(string $tag): string
    {
        return $this->prefix().': '.$tag;
    }
}
