<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Attributes\QueryParameter;

/**
 * What {@see CustomFilterReader} recovered from a custom filter class: its declaring `file` (a
 * fragment-cache dependency), an optional class-level `#[QueryParameter]` override `attribute`, and —
 * when there is no attribute — the `column` its `__invoke` body filters on. `attribute` and `column`
 * are mutually exclusive: the attribute is the explicit override, so body inference is not consulted
 * when it is present.
 */
final readonly class CustomFilterFacts
{
    public function __construct(
        public ?string $file = null,
        public ?QueryParameter $attribute = null,
        public ?string $column = null,
    ) {}
}
