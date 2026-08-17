<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * A page-size query key an endpoint demonstrably reads off the request, recovered by
 * {@see RequestPageSizeReader}. `default` is the fallback the read was WRITTEN with, and only when that
 * was a literal — a fallback that is itself a parameter belongs to whichever caller supplied it, so
 * there is no honest default to publish.
 */
final readonly class RequestPageSizeKey
{
    public function __construct(
        public string $key,
        public ?int $default = null,
    ) {}
}
