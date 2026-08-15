<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * A request DTO whose members are the array shapes the rule vocabulary has one word for: a keyed map, a
 * list, a map of maps, a list of maps and a constant shape. The generics live in the constructor
 * `@param` block, where a real DTO writes them. Only ever reflected — the property types under test are
 * fed in as metadata, so the recovery half is proven against the real engine elsewhere.
 */
final class ContainerShapeData extends Data
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $tags
     * @param  array<string, array<string, string|null>>  $theme
     * @param  list<array<string, int>>  $counters
     * @param  array{width: int, label?: string}  $box
     * @param  array<string, mixed>|Optional  $extras
     */
    public function __construct(
        public readonly array $settings,
        public readonly array $tags,
        public readonly array $theme,
        public readonly array $counters,
        public readonly array $box,
        public readonly array|Optional $extras = new Optional,
    ) {}
}
