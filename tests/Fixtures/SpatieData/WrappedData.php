<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Attributes\SchemaName;
use Spatie\LaravelData\Data;

/**
 * A Data class that overrides its response wrap (`defaultWrap()` → a `WrapType::Defined` key) and opts
 * into two of spatie's request query-string partials by overriding the matching `allowedRequest*()`
 * methods — the idiomatic way a resource declares wrapping + includable/only partials. Only ever
 * reflected (the wrap key is read statically off this file; the methods are never invoked).
 */
#[SchemaName('Wrapped')]
final class WrappedData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    protected function defaultWrap(): string
    {
        return 'record';
    }

    /**
     * @return array<int, string>|null
     */
    public static function allowedRequestIncludes(): ?array
    {
        return ['tags'];
    }

    /**
     * @return array<int, string>|null
     */
    public static function allowedRequestOnly(): ?array
    {
        return null;
    }
}
