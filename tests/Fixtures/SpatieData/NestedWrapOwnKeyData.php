<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/**
 * A class with its own `defaultWrap()` and no global wrap. Its root goes out under `record`, and the
 * nested collection is sent BARE — spatie resolves a nested envelope from the global config alone.
 */
final class NestedWrapOwnKeyData extends Data
{
    /** @param list<NestedWrapItemData> $things */
    public function __construct(public array $things) {}

    public static function defaultWrap(): string
    {
        return 'record';
    }
}
