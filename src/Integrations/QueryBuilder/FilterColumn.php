<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * The typed shape a subject model pins for an exact-filter column, resolved by
 * {@see FilterColumnResolver}: an enum (its FQCN — the extension expands it through the shared enum
 * machinery to backing values + `x-enumDescriptions`), a scalar schema from a native cast or the
 * primary key, or none (nothing types the column → the filter stays a plain string).
 * `dependencyFiles` are the files the resolution read (the enum's declaring file) so they join the
 * fragment-cache key.
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

    /** No recognised shape — the filter keeps its plain-string form. */
    public static function none(): self
    {
        return new self(self::KIND_NONE);
    }

    /**
     * The same column with `$files` joined onto its dependency set. A refusal carries the files it
     * read to refuse: edited, any of them could become an answer, and a warm fragment must see that.
     *
     * @param  list<string>  $files
     */
    public function withDependencyFiles(array $files): self
    {
        return new self(
            $this->kind,
            $this->enum,
            $this->scalarSchema,
            array_values(array_unique([...$this->dependencyFiles, ...$files])),
        );
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
