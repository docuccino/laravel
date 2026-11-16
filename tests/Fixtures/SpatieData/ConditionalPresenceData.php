<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Validation\Rules\ProhibitedIf;
use Illuminate\Validation\Rules\RequiredIf;
use Spatie\LaravelData\Attributes\Validation\Prohibited;
use Spatie\LaravelData\Attributes\Validation\ProhibitedIf as ProhibitedIfField;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\RequiredIf as RequiredIfField;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * `#[Required]` and `#[Prohibited]` handed a condition settled where it is written, beside the cross-field
 * `#[RequiredIf]` and `#[ProhibitedIf]`, on scalar properties and on nested Data.
 */
final class ConditionalPresenceData extends Data
{
    public function __construct(
        public string $name,
        public string|Optional $kind,
        #[Required(new RequiredIf(true))]
        public string $email,
        #[Required(new RequiredIf(false))]
        public string $phone,
        #[RequiredIfField('kind', 'business')]
        public string $vat,
        #[Prohibited(new ProhibitedIf(true))]
        public string|Optional $legacy,
        #[Prohibited(new ProhibitedIf(false))]
        public string|Optional $nickname,
        #[ProhibitedIfField('kind', 'business')]
        public string|Optional $referrer,
        #[Required(new RequiredIf(false))]
        public ConditionalPresenceAddressData $home,
        #[RequiredIfField('kind', 'business')]
        public ConditionalPresenceAddressData $office,
        #[Prohibited(new ProhibitedIf(false))]
        public ConditionalPresenceAddressData|Optional $billing,
        #[Prohibited(new ProhibitedIf(true))]
        public ConditionalPresenceAddressData|Optional $archive,
    ) {}
}
