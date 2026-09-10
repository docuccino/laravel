<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/**
 * A Data class whose `defaultWrap()` returns a constant rather than a literal — the shape a codebase
 * takes once the key is shared with a client or a test. Spatie wraps under whatever it returns, so the
 * key cannot be read off the source. Only ever reflected.
 */
final class ComputedWrapData extends Data
{
    public const ENVELOPE = 'record';

    public function __construct(
        public int $id,
    ) {}

    protected function defaultWrap(): string
    {
        return self::ENVELOPE;
    }
}
