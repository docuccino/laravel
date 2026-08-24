<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/**
 * One property per JSON type an `@example` can be written against, each with a real constructor default
 * so the `default` the reflector reads and the `example` the docblock states land in the same node — the
 * pair that made the defect visible, since one was typed and the other was not.
 *
 * The `@example` tags here are what the real engine reads off these docblocks; a stub engine mirrors
 * them as the strings the docblock reader hands over, which is the whole point — text is all a tag can
 * hold. `n/a` on an `int` is the shape that has no reading at all. Only ever reflected.
 */
final class ExampleTypesData extends Data
{
    public function __construct(
        /**
         * Whether the team must sign in through SSO.
         *
         * @example false
         */
        public readonly bool $sso_required = false,
        /**
         * How many seats the plan carries.
         *
         * @example 7
         */
        public readonly int $seats = 7,
        /**
         * The share of seats in use.
         *
         * @example 0.25
         */
        public readonly float $utilisation = 0.5,
        /**
         * The permissions every member of the team holds.
         *
         * @example ["listing.view", "listing.create"]
         *
         * @var list<string>
         */
        public readonly array $permissions = [],
        /**
         * The team's slug.
         *
         * @example acme
         */
        public readonly string $slug = 'acme',
        /**
         * How many days of history the plan retains, where the plan says.
         *
         * @example null
         */
        public readonly ?int $retention_days = null,
        /**
         * The seat count at last renewal.
         *
         * @example n/a
         */
        public readonly int $renewal_seats = 0,
    ) {}
}
