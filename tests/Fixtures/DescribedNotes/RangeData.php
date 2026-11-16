<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DescribedNotes;

use Docuccino\Attributes\Description;
use Spatie\LaravelData\Attributes\Validation\After;
use Spatie\LaravelData\Attributes\Validation\ProhibitedIf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/** A Data class whose properties carry their author's prose beside rules that state a fact. */
final class RangeData extends Data
{
    public function __construct(
        public string $from,
        /** End of the range. */
        #[After('from')]
        public string|Optional $until,
        public string|Optional $kind,
        #[Description('What the lister goes by.')]
        #[ProhibitedIf('kind', 'business')]
        public string|Optional $nickname,
    ) {}
}
