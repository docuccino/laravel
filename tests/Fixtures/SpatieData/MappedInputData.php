<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Attributes\Description;
use Docuccino\Core\Inference\ClassMetadata;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * An input DTO whose keys are remapped on the way IN — the case where the property a declaration sits
 * on and the key the request accepts are two different names. The prose has to follow the property to
 * whichever key it publishes under.
 *
 * The docblock text here is the source of truth the stubbed
 * {@see ClassMetadata} mirrors; only ever reflected.
 */
#[MapInputName(SnakeCaseMapper::class)]
final class MappedInputData extends Data
{
    public function __construct(
        /** The blueprint whose field set this is built from. */
        #[Required, StringType, Uuid]
        public readonly string $blueprintId,
        #[Description(text: 'Where the submission came from.')]
        #[Required, StringType]
        public readonly string $sourceChannel,
    ) {}
}
