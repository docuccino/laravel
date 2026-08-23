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
    ) {}

    /**
     * Built from the effective `query-builder` config bag; an empty one yields defaults, unrecovered.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self(recovered: false);
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
        );
    }

    /** Whether list values split on the comma the array modelling serialises. */
    public function splitsOnComma(): bool
    {
        return $this->delimiter === ',';
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
