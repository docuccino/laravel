<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\Validation\Distinct;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\NotIn;
use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Choice, size and uniqueness attributes on array-typed properties, lists and maps, each beside the `array`
 * rule laravel-data adds to an array-typed property; and a `rules()` override that replaces that rule.
 *
 * @param  list<string>|Optional  $tags
 * @param  list<string>|Optional  $blocked
 * @param  array<string, string>|Optional  $labels
 * @param  array<string, string>|Optional  $banned
 * @param  list<string>|null  $maybe
 * @param  list<string>|Optional  $statuses
 * @param  list<string>|Optional  $ruled
 * @param  list<string>|Optional  $few
 * @param  array<string, string>|Optional  $capped
 * @param  list<string>|Optional  $unique
 * @param  list<string>|Optional  $overridden
 */
final class ArrayChoicesData extends Data
{
    public function __construct(
        #[In('a', 'b')]
        public array|Optional $tags,
        #[NotIn('a', 'b')]
        public array|Optional $blocked,
        #[In('a', 'b')]
        public array|Optional $labels,
        #[NotIn('a', 'b')]
        public array|Optional $banned,
        #[In('a', 'b')]
        public ?array $maybe,
        #[Enum(ListingStatus::class)]
        public array|Optional $statuses,
        #[Rule('in:a,b')]
        public array|Optional $ruled,
        #[Max(2)]
        public array|Optional $few,
        #[Max(1)]
        public array|Optional $capped,
        #[Distinct]
        public array|Optional $unique,
        public array|Optional $overridden,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'overridden' => ['in:a,b'],
        ];
    }
}
