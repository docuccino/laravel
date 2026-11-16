<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Rule strings piped together in a `#[Rule]`, alone or in a list, and in a `rules()` override, alone or in a
 * list; and a regex holding a pipe, written both ways.
 */
final class PipedRuleData extends Data
{
    public function __construct(
        #[Rule('string|max:3')]
        public string|Optional $piped,
        #[Rule(['string|max:3', 'min:2'])]
        public string|Optional $listed,
        #[Rule('regex:/^(ab|cd)$/')]
        public string|Optional $pattern,
        public string|Optional $overridden,
        public string|Optional $overriddenPattern,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'overridden' => ['string|max:3'],
            'overriddenPattern' => 'regex:/^(ab|cd)$/',
        ];
    }
}
