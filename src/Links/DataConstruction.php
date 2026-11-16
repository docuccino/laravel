<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Links;

/**
 * One place a Data object is built, as {@see DataSourceVisitor} saw it: from a model (`from($model)`,
 * `collect($models)`), from constructor arguments, from an array payload, or from something it cannot
 * read. A source is `[model, column]`, the column possibly a {@see BodyColumns} key marker, or null where
 * the value is anything but a model's column.
 *
 * @internal
 */
final readonly class DataConstruction
{
    public const string MODEL = 'model';

    public const string ARGUMENTS = 'arguments';

    public const string PAYLOAD = 'payload';

    public const string UNKNOWN = 'unknown';

    /**
     * @param  array<int|string, array{0: string, 1: string}|null>  $sources  argument (position or name) or payload key → source
     */
    private function __construct(
        public string $kind,
        public ?string $model = null,
        public array $sources = [],
    ) {}

    public static function model(string $model): self
    {
        return new self(self::MODEL, $model);
    }

    /**
     * @param  array<int|string, array{0: string, 1: string}|null>  $sources
     */
    public static function arguments(array $sources): self
    {
        return new self(self::ARGUMENTS, sources: $sources);
    }

    /**
     * @param  array<string, array{0: string, 1: string}|null>  $sources
     */
    public static function payload(array $sources): self
    {
        return new self(self::PAYLOAD, sources: $sources);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }
}
