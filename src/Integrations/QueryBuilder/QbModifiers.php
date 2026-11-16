<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * The `->default(<const>)` / `->nullable()` / `->delimiter(<const>)` facts an allow-list entry carries. They
 * can be written at the call site or inside the helper that built the entry, so they are recovered from two
 * places and merged.
 *
 * @phpstan-import-type FilterDefault from QbEntry
 */
final readonly class QbModifiers
{
    /**
     * @param  FilterDefault  $default
     */
    public function __construct(
        public bool $hasDefault = false,
        public string|int|float|bool|array|null $default = null,
        public bool $nullable = false,
        public ?string $delimiter = null,
    ) {}

    /**
     * A recovered `->default()`. Spatie makes a filter defaulting to `null` nullable as well, since that
     * default is applied as a `null` the filter must then accept.
     *
     * @param  FilterDefault  $default
     */
    public static function defaulting(string|int|float|bool|array|null $default): self
    {
        return new self(true, $default, $default === null);
    }

    /**
     * These facts filled in from an inner set — the outer (call-site) default and delimiter win where both
     * have one, as the call applied last does.
     */
    public function merge(self $inner): self
    {
        return new self(
            $this->hasDefault || $inner->hasDefault,
            $this->hasDefault ? $this->default : $inner->default,
            $this->nullable || $inner->nullable,
            $this->delimiter ?? $inner->delimiter,
        );
    }
}
