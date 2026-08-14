<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * The `->default(<const>)` / `->nullable()` facts an allow-list entry carries. They can be written at the
 * call site or inside the helper that built the entry, so they are recovered from two places and merged.
 */
final readonly class QbModifiers
{
    public function __construct(
        public bool $hasDefault = false,
        public string|int|float|bool|null $default = null,
        public bool $nullable = false,
    ) {}

    /** These facts filled in from an inner set — the outer (call-site) default wins where both have one. */
    public function merge(self $inner): self
    {
        return new self(
            $this->hasDefault || $inner->hasDefault,
            $this->hasDefault ? $this->default : $inner->default,
            $this->nullable || $inner->nullable,
        );
    }
}
