<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\MorphType;

use RuntimeException;

/** Answers with each model whose polymorphic type column the document describes. */
final class MorphTypeController
{
    public function comment(): Comment
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    public function reaction(): Reaction
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    public function bookmark(): Bookmark
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    public function like(): Like
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }

    public function flag(): Flag
    {
        throw new RuntimeException(__METHOD__.' is documented, not dispatched');
    }
}
