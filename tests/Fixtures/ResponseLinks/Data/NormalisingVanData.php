<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Normalizers\ArrayableNormalizer;

/** A van read through normalizers of its own, which turn a model into its serialised array first. */
final class NormalisingVanData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $plate,
    ) {}

    public static function normalizers(): array
    {
        return [ArrayableNormalizer::class];
    }
}
