<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames;

use Docuccino\Attributes\Hidden;
use Docuccino\Attributes\SchemaName;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;

/**
 * One class, two shapes: what a client SENDS is spatie's rules under the input names, what it RECEIVES
 * is the properties under the output names, less what the class hides. An app publishing both is the
 * ordinary case, not the exotic one — so the two shapes must never be able to want the same name.
 */
#[SchemaName('Portal')]
#[Hidden('token')]
final class PortalData extends Data
{
    public function __construct(
        public int $id,
        #[MapName('handle', 'displayName')]
        public string $name,
        public string $token,
    ) {}
}
