<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * The `spatie/laravel-query-builder` settings that name the request keys. An app can rename
 * `filter`/`sort`/`include`/`fields`/`append` under `query-builder.parameters.*`, so documented names
 * have to follow the effective config. A pure value object, dataset-testable in isolation.
 *
 * When the config bag is missing entirely — package installed but its namespace never merged — this falls
 * back to the package defaults and records `recovered = false`, so the extension can say so in a
 * diagnostic instead of quietly assuming.
 */
final readonly class QueryBuilderConfig
{
    /** The major whose minting/expansion grammar the documented enums encode. */
    private const SUPPORTED_MAJOR = 7;

    public function __construct(
        public string $filter = 'filter',
        public string $sort = 'sort',
        public string $include = 'include',
        public string $fields = 'fields',
        public string $append = 'append',
        public bool $recovered = true,
        // The package throws InvalidFilter/Sort/Includes (400) for anything unknown unless EVERY
        // `disable_*_exception` is set. On by default, so QB operations document a 400.
        public bool $strict = true,
        // `query-builder.suffixes.*`: a bare-string include also legalizes its Count/Exists forms
        // under these suffixes, so they shape the documented include enum. An empty suffix is kept
        // as written — Spatie's Str::endsWith skips empty needles, so it neither matches nor mints.
        public string $countSuffix = 'Count',
        public string $existsSuffix = 'Exists',
        // `query-builder.delimiter`: what sort/include/filter values split on. The comma-array list
        // modelling is only truthful on the default; anything else degrades the lists.
        public string $delimiter = ',',
        // The installed package major. Below v7 the include grammar differs (the explicit factory
        // itself minted Count/Exists + partials) and the old config keys are not read, so the
        // sort/include enums degrade to plain strings.
        public int $spatieMajor = self::SUPPORTED_MAJOR,
    ) {}

    /**
     * Built from the effective `query-builder` config bag; an empty one yields defaults, unrecovered.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config, int $spatieMajor = self::SUPPORTED_MAJOR): self
    {
        if ($config === []) {
            return new self(recovered: false, spatieMajor: $spatieMajor);
        }

        $parameters = is_array($config['parameters'] ?? null) ? $config['parameters'] : [];
        $suffixes = is_array($config['suffixes'] ?? null) ? $config['suffixes'] : [];

        return new self(
            filter: self::string($parameters, 'filter', 'filter'),
            sort: self::string($parameters, 'sort', 'sort'),
            include: self::string($parameters, 'include', 'include'),
            fields: self::string($parameters, 'fields', 'fields'),
            append: self::string($parameters, 'append', 'append'),
            strict: ! (
                ($config['disable_invalid_filter_query_exception'] ?? false) === true
                && ($config['disable_invalid_sort_query_exception'] ?? false) === true
                && ($config['disable_invalid_includes_query_exception'] ?? false) === true
            ),
            countSuffix: self::rawString($suffixes, 'count', 'Count'),
            existsSuffix: self::rawString($suffixes, 'exists', 'Exists'),
            delimiter: self::rawString($config, 'delimiter', ','),
            spatieMajor: $spatieMajor,
        );
    }

    /**
     * The major of a composer version string. Null or an unparseable version (`dev-main`) reads as the
     * supported major: composer is the only supported install path, so a runtime API that can't answer
     * is a test-harness artifact, not an old install.
     */
    public static function majorOf(?string $version): int
    {
        return $version !== null && preg_match('/^v?(\d+)\./', $version, $matches) === 1
            ? (int) $matches[1]
            : self::SUPPORTED_MAJOR;
    }

    /** Whether the installed package predates the grammar the sort/include enums encode. */
    public function legacyPackage(): bool
    {
        return $this->spatieMajor < self::SUPPORTED_MAJOR;
    }

    /** Whether list values split on the comma the array modelling serialises. */
    public function splitsOnComma(): bool
    {
        return $this->delimiter === ',';
    }

    /**
     * Whether an allow-list is published as a value enum, and therefore whether the SDK member names
     * that ride on it reach the document at all. The one predicate both the emitter and the collision
     * report read: a custom delimiter degrades the list to a plain string exactly as an old package
     * does, so a report deriving its own answer would claim names nobody can see.
     */
    public function mintsNames(): bool
    {
        return ! $this->legacyPackage() && ($this->splitsOnComma() || $this->delimiter === '');
    }

    /** The bracketed `filter[<name>]`-style key for a filter member. */
    public function filterKey(string $name): string
    {
        return sprintf('%s[%s]', $this->filter, $name);
    }

    /** The bracketed `fields[<type>]`-style key for a sparse-fieldset member. */
    public function fieldsKey(string $type): string
    {
        return sprintf('%s[%s]', $this->fields, $type);
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     */
    private static function string(array $parameters, string $key, string $default): string
    {
        $value = $parameters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Like {@see string()} but an empty string is a value, not an omission — Spatie honors an empty
     * suffix or delimiter as written.
     *
     * @param  array<array-key, mixed>  $config
     */
    private static function rawString(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}
