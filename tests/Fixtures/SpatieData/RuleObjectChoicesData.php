<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Validation\Rules\Enum as EnumRule;
use Illuminate\Validation\Rules\ExcludeIf;
use Illuminate\Validation\Rules\In as InRule;
use Illuminate\Validation\Rules\NotIn as NotInRule;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Attributes\Validation\Exclude;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\NotIn;
use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Workbench\App\Enums\WidgetPriority;
use Workbench\App\Enums\WidgetStatus;

/**
 * Framework rule objects handed to spatie's validation attributes: inside `#[Rule]`, bare or in a list, and
 * in place of an `#[Enum]`, `#[In]`, `#[NotIn]` or `#[Exclude]` attribute's own parameters; an `#[Enum]`
 * its `only`/`except` narrow; the `In`/`NotIn` an `#[In]`/`#[NotIn]` builds from booleans or a value
 * holding a comma; and the quoted string such an object stringifies to, written in a `#[Rule]`.
 */
final class RuleObjectChoicesData extends Data
{
    public function __construct(
        #[Rule(new EnumRule(WidgetStatus::class))]
        public string $status,
        #[Rule([new InRule(['small', 'large'])])]
        public string $size,
        #[Rule([new EnumRule(WidgetPriority::class), 'required'])]
        public int $priority,
        #[Rule([new NotInRule(['legacy'])])]
        public string $code,
        #[Enum(new EnumRule(WidgetStatus::class))]
        public string $stage,
        #[Enum(WidgetStatus::class, only: [WidgetStatus::Draft, WidgetStatus::Published])]
        public string $phase,
        #[Enum(WidgetStatus::class, except: [WidgetStatus::Archived])]
        public string $state,
        #[In(new InRule(['red', 'blue']))]
        public string $colour,
        #[NotIn(new NotInRule(['void']))]
        public string $mark,
        #[Exclude(new ExcludeIf(false)), Max(3)]
        public ?string $note,
        #[In([true, false])]
        public bool|Optional $consent,
        #[NotIn([false])]
        public bool|Optional $agreed,
        #[In(['a,b', 'c'])]
        public string|Optional $pair,
        #[Rule('in:"x,y",z')]
        public string|Optional $quoted,
    ) {}
}
