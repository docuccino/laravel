<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Attributes\Hidden as DocuccinoHidden;
use Docuccino\Attributes\HiddenFromRequest;
use Spatie\LaravelData\Attributes\FromRouteParameter;
use Spatie\LaravelData\Data;

/**
 * Idiomatic request-DTO exclusion surfaces: a plain body field, a `#[FromRouteParameter]` property
 * (populated from the route binding, not the body), a Docuccino `#[HiddenFromRequest]` property
 * (explicitly dropped from the request body), and a Docuccino `#[Hidden]` property (hidden from
 * OUTPUT only — it stays a sendable request field, deliberately, so the leakage lint can surface it).
 * Only ever reflected.
 */
final class RequestExclusionData extends Data
{
    public function __construct(
        public string $name,
        #[FromRouteParameter('id')]
        public string $id,
        #[HiddenFromRequest]
        public string $internalToken,
        #[DocuccinoHidden]
        public string $secret,
    ) {}
}
