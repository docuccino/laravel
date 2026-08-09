<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * The `spatie/laravel-query-builder` settings that name the request keys — the package lets an app
 * rename `filter`/`sort`/`include`/`fields`/`append` under `query-builder.parameters.*`, so the
 * documented parameter names must follow the effective config rather than assume the defaults. A pure
 * value object (dataset-testable in isolation); {@see fromArray()} reads the effective `query-builder`
 * config bag and, when it is absent (the package installed but its config never merged, e.g. a unit
 * context), falls back to the package defaults and records `recovered = false` so the extension emits
 * an info diagnostic rather than silently assuming default names.
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
        // Strict mode: the package throws InvalidFilter/Sort/Includes query exceptions (HTTP 400) for
        // an unknown filter/sort/include unless every `disable_*_exception` is set. On by default
        // (spatie's defaults), so QB operations document a 400.
        public bool $strict = true,
    ) {}

    /**
     * Build from the effective `query-builder` config bag. An empty bag (namespace never merged)
     * yields package defaults with `recovered = false`.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self(recovered: false);
        }

        $parameters = is_array($config['parameters'] ?? null) ? $config['parameters'] : [];

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
        );
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
}
