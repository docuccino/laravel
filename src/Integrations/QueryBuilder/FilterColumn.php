<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * The typed shape a subject-model cast pins for an exact-filter column, resolved by
 * {@see FilterColumnResolver}: an enum (its FQCN — the extension expands it through the shared enum
 * machinery to backing values + `x-enumDescriptions`), a native cast scalar schema, or none (no
 * recognised cast → the filter stays a plain string). `dependencyFiles` are the files the resolution
 * read (the enum's declaring file) so they join the fragment-cache key.
 */
final readonly class FilterColumn
{
    public const KIND_ENUM = 'enum';

    public const KIND_SCALAR = 'scalar';

    public const KIND_NONE = 'none';

    /**
     * @param  array<string, mixed>|null  $scalarSchema
     * @param  list<string>  $dependencyFiles
     */
    private function __construct(
        public string $kind,
        public ?string $enum = null,
        public ?array $scalarSchema = null,
        public array $dependencyFiles = [],
    ) {}

    /** No recognised cast — the filter keeps its plain-string shape. */
    public static function none(): self
    {
        return new self(self::KIND_NONE);
    }

    /**
     * @param  list<string>  $dependencyFiles
     */
    public static function enum(string $enum, array $dependencyFiles = []): self
    {
        return new self(self::KIND_ENUM, enum: $enum, dependencyFiles: $dependencyFiles);
    }

    /**
     * @param  array<string, mixed>  $scalarSchema
     * @param  list<string>  $dependencyFiles
     */
    public static function scalar(array $scalarSchema, array $dependencyFiles = []): self
    {
        return new self(self::KIND_SCALAR, scalarSchema: $scalarSchema, dependencyFiles: $dependencyFiles);
    }

    public function isEnum(): bool
    {
        return $this->kind === self::KIND_ENUM;
    }

    public function isScalar(): bool
    {
        return $this->kind === self::KIND_SCALAR;
    }
}
