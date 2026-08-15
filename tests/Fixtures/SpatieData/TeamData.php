<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/**
 * A request DTO keying its members by id — `array<string, TeamMemberData>`, the generic written in the
 * constructor `@param` block where a real DTO writes it. Only ever reflected.
 */
final class TeamData extends Data
{
    /**
     * @param  array<string, TeamMemberData>  $members
     */
    public function __construct(
        public readonly array $members,
    ) {}
}
