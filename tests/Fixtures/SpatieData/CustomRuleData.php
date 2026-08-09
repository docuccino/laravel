<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Laravel\Tests\Fixtures\Rules\BankReference;
use Docuccino\Laravel\Tests\Fixtures\Rules\OpaqueCheck;
use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Data;

/** A Data class carrying rule objects in `#[Rule]` — one documented, one not. */
final class CustomRuleData extends Data
{
    public function __construct(
        #[Rule(new BankReference)]
        public string $reference,
        #[Rule(new OpaqueCheck)]
        public string $token,
    ) {}
}
