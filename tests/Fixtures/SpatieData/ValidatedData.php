<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\Validation\Accepted;
use Spatie\LaravelData\Attributes\Validation\ActiveUrl;
use Spatie\LaravelData\Attributes\Validation\Alpha;
use Spatie\LaravelData\Attributes\Validation\AlphaDash;
use Spatie\LaravelData\Attributes\Validation\AlphaNumeric;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Attributes\Validation\DigitsBetween;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\EndsWith;
use Spatie\LaravelData\Attributes\Validation\Filled;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Ip;
use Spatie\LaravelData\Attributes\Validation\Json;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\MaxDigits;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\MinDigits;
use Spatie\LaravelData\Attributes\Validation\NotIn;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Attributes\Validation\Present;
use Spatie\LaravelData\Attributes\Validation\Prohibited;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Attributes\Validation\Sometimes;
use Spatie\LaravelData\Attributes\Validation\StartsWith;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Attributes\Validation\Url;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Data;

/**
 * A Data fixture carrying one property per supported spatie validation attribute (plus one
 * unsupported `#[Accepted]`), so the reflector's attribute→rule-token map is exercised exhaustively
 * and the unknown-attribute degradation path is covered. Only ever reflected.
 */
final class ValidatedData extends Data
{
    public function __construct(
        #[Required] public string $required,
        #[Nullable] public ?string $nullable,
        #[Sometimes] public string $sometimes,
        #[Present] public string $present,
        #[Prohibited] public string $prohibited,
        #[Filled] public string $filled,
        #[Email] public string $email,
        #[Url] public string $url,
        #[ActiveUrl] public string $activeUrl,
        #[Uuid] public string $uuid,
        #[Ulid] public string $ulid,
        #[Numeric] public string $numeric,
        #[IntegerType] public int $integer,
        #[StringType] public string $string,
        #[BooleanType] public bool $boolean,
        #[ArrayType] public array $arrayType,
        #[Alpha] public string $alpha,
        #[AlphaNumeric] public string $alphaNumeric,
        #[AlphaDash] public string $alphaDash,
        #[Date] public string $date,
        #[Json] public string $json,
        #[Ip] public string $ip,
        #[Max(500)] public string $max,
        #[Min(1)] public int $min,
        #[Size(10)] public string $size,
        #[Between(1, 10)] public int $between,
        #[In('draft', 'published')] public string $in,
        #[NotIn('x', 'y')] public string $notIn,
        #[Regex('/^[a-z]+$/')] public string $regex,
        #[DateFormat('Y-m-d')] public string $dateFormat,
        #[MaxDigits(5)] public int $maxDigits,
        #[MinDigits(2)] public int $minDigits,
        #[DigitsBetween(1, 5)] public int $digitsBetween,
        #[StartsWith('a')] public string $startsWith,
        #[EndsWith('z')] public string $endsWith,
        #[Accepted] public bool $accepted,
    ) {}
}
