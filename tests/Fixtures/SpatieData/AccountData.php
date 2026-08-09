<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * An idiomatic Data class exercising the harder spatie surfaces Wave B added: a CLASS-LEVEL mapper
 * (`#[MapName(SnakeCaseMapper::class)]` renames every key to snake_case), a `DateTimeInterface`
 * property (serialised as a formatted string), a backed-enum property, a `#[Computed]` output-only
 * property, a defaulted property, a `#[Rule('...')]` escape-hatch, a nested Data property, and a
 * `#[DataCollectionOf]` collection. Only ever reflected — never instantiated.
 */
#[MapName(SnakeCaseMapper::class)]
final class AccountData extends Data
{
    /**
     * @param  DataCollection<int, TagData>  $tags
     */
    public function __construct(
        public string $displayName,
        public \DateTimeImmutable $createdAt,
        #[Enum(AccountStatus::class)]
        public AccountStatus $status,
        #[Rule('max:5')]
        public string $code,
        public AddressData $address,
        #[DataCollectionOf(TagData::class)]
        public DataCollection $tags,
        #[Computed]
        public string $summary = '',
        public string $country = 'GB',
    ) {}
}
