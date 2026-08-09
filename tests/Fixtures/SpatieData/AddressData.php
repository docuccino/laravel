<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/** A nested Data object used to exercise request-rule recursion (address.city, address.postcode). */
final class AddressData extends Data
{
    public function __construct(
        public string $city,
        public string $postcode,
    ) {}
}
