<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Attributes\Description;
use Docuccino\Attributes\Example;
use Docuccino\Core\Inference\ClassMetadata;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Data;

/**
 * An input DTO written the way an application writes one: prose on the properties, validation stated as
 * spatie attributes. The request body is recovered from those RULES, so the prose has to be matched back
 * onto fields the rules named — `reference` carries only a docblock, `blueprint_id` contests its docblock
 * with the attribute form, and `token` is validated but says nothing.
 *
 * The docblock text here is the source of truth the stubbed {@see ClassMetadata}
 * mirrors; only ever reflected.
 */
#[Description(text: 'Everything the submission form captured, as one payload.')]
final class DescribedInputData extends Data
{
    public function __construct(
        /**
         * The caller's own reference for this submission.
         *
         * @example INV-2291
         */
        #[Required, StringType, Max(64)]
        public readonly string $reference,
        /**
         * The blueprint whose field set this is built from.
         *
         * @example 8f14e45f-ceea-467a-9c2e-6f5f0c1a1b2c
         */
        #[Description(text: 'The blueprint this position is built from.')]
        #[Example(value: '0b4a1d7e-1111-4222-8333-444455556666')]
        #[Required, StringType, Uuid]
        public readonly string $blueprint_id,
        #[Required, StringType, Max(32)]
        public readonly string $token,
    ) {}
}
