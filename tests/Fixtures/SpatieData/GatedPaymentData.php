<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\Validation\ExcludeUnless;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\RequiredIf;
use Spatie\LaravelData\Data;

/**
 * Presence under conditions, written the ways a Data class writes it: an exclude attribute with and
 * without an explicit `#[Required]` after it, and a conditional-required attribute on a property whose
 * type alone would make it required.
 */
final class GatedPaymentData extends Data
{
    public function __construct(
        #[In('card', 'transfer')]
        public string $method,
        #[ExcludeUnless('method', 'card')]
        public string $cardNumber,
        #[ExcludeUnless('method', 'transfer'), Required]
        public string $iban,
        #[RequiredIf('method', 'card')]
        public string $reference,
    ) {}
}
