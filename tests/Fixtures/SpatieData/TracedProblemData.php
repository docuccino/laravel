<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * The loadable twin of the fixture app's problem-document Data class: an RFC 9457 body whose last two
 * members carry spatie's `Optional` marker, so a construction that leaves either out renders without the
 * key. Only ever reflected — the property declarations are what the mapper reads.
 */
final class TracedProblemData extends Data
{
    /**
     * @param  list<string>|Optional  $errors
     */
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public string $detail,
        public string|Optional $instance = new Optional,
        public array|Optional $errors = new Optional,
    ) {}
}
