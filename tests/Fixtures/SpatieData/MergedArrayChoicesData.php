<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\MergeValidationRules;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * A `rules()` choice list merged into the `array` rule laravel-data adds to an array-typed property.
 *
 * @param  list<string>|Optional  $merged
 */
#[MergeValidationRules]
final class MergedArrayChoicesData extends Data
{
    public function __construct(
        public array|Optional $merged,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'merged' => ['in:a,b'],
        ];
    }
}
