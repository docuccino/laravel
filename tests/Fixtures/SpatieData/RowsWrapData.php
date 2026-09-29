<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/** The wrap key the class below chooses by `insteadof`. */
trait WrapsAsRows
{
    protected function defaultWrap(): string
    {
        return 'rows';
    }
}

/** A second trait naming a wrap key, written after the chosen one; the class below sets it aside. */
trait WrapsAsItems
{
    protected function defaultWrap(): string
    {
        return 'items';
    }
}

/**
 * A Data class taking its `defaultWrap()` from one of two traits in its own file, by `insteadof` — the
 * key it is sent under is the chosen trait's, whichever trait the file writes last.
 */
final class RowsWrapData extends Data
{
    use WrapsAsItems, WrapsAsRows {
        WrapsAsRows::defaultWrap insteadof WrapsAsItems;
    }

    public function __construct(
        public int $id,
    ) {}
}
