<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/**
 * A nested collection under a class whose own wrap key is a constant rather than a literal. The root's
 * key is in doubt; what happens under it is not, since spatie resolves a nested collection's envelope
 * from the global config alone. Only ever reflected.
 */
final class NestedWrapUnreadKeyData extends Data
{
    public const ENVELOPE = 'record';

    /** @param list<NestedWrapItemData> $things */
    public function __construct(public array $things) {}

    protected function defaultWrap(): string
    {
        return self::ENVELOPE;
    }
}
