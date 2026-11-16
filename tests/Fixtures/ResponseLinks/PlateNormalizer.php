<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

use Spatie\LaravelData\Normalizers\Normalizer;

/** A normalizer an application wrote: it reads a van with its plate upper-cased, before laravel-data's own. */
final class PlateNormalizer implements Normalizer
{
    public function normalize(mixed $value): ?array
    {
        return $value instanceof Van ? ['id' => $value->id, 'plate' => strtoupper($value->plate)] : null;
    }
}
