<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ConditionalAcceptance;

use Spatie\LaravelData\Attributes\Validation\AcceptedIf;
use Spatie\LaravelData\Attributes\Validation\DeclinedIf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/** Fields that must be accepted or declined only while `kind` is `business`, as validation attributes. */
final class ConsentData extends Data
{
    public function __construct(
        public string $kind,
        #[AcceptedIf('kind', 'business')]
        public bool $terms,
        #[DeclinedIf('kind', 'business')]
        public bool|Optional $marketing,
    ) {}
}
