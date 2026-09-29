<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Attributes\QueryParameter;

/**
 * What {@see CustomFilterReader} recovered from a custom filter class: the `files` its declaration spans
 * (fragment-cache dependencies, its own first — the body read may be a parent's or a trait's), an optional class-level `#[QueryParameter]` override `attribute`, and —
 * when there is no attribute — the `column` its `__invoke` body filters on. `attribute` and `column`
 * are mutually exclusive: the attribute is the explicit override, so body inference is not consulted
 * when it is present.
 */
final readonly class CustomFilterFacts
{
    public function __construct(
        /** @var list<string> */
        public array $files = [],
        public ?QueryParameter $attribute = null,
        public ?string $column = null,
    ) {}
}
