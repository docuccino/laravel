<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DescribedNotes;

use Carbon\CarbonImmutable;
use Docuccino\Attributes\Description;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Transformers\DateTimeInterfaceTransformer;

/**
 * A Data class sent in a response, under an app `data.date_format` no `format` keyword names, its date
 * properties described by their author and not.
 */
final class LogbookData extends Data
{
    public function __construct(
        /** The day the logbook was closed. */
        public CarbonImmutable $closedOn,
        #[Description('When the logbook was opened.')]
        public CarbonImmutable $openedOn,
        public CarbonImmutable $sealedOn,
        /** When the logbook was last reviewed, if it has been. */
        public ?CarbonImmutable $reviewedOn,
        /** When the logbook was stamped. */
        #[WithCast(DateTimeInterfaceCast::class, format: 'U')]
        public CarbonImmutable $stampedAt,
        /** When the logbook was archived. */
        #[WithTransformer(DateTimeInterfaceTransformer::class, format: 'Y-m-d')]
        public CarbonImmutable $archivedOn,
    ) {}
}
