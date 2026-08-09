<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\JsonApiPaginate;

/**
 * The `spatie/laravel-json-api-paginate` settings that shape the documented pagination parameters, as a
 * pure value object. Every parameter name (`page[number]`, `page[size]`, `page[cursor]`) is renamable in
 * the package's published config, so {@see fromArray()} reads the effective `json-api-paginate.*` bag.
 * An absent bag — package installed but never booted — falls back to package defaults and sets
 * `recovered = false`, so the extension can say so in a diagnostic rather than quietly guess.
 */
final readonly class JsonApiPaginateConfig
{
    public const MODE_LENGTH = 'length';

    public const MODE_SIMPLE = 'simple';

    public const MODE_CURSOR = 'cursor';

    public function __construct(
        public string $pageParameter = 'page',
        public string $numberParameter = 'number',
        public string $sizeParameter = 'size',
        public string $cursorParameter = 'cursor',
        public string $methodName = 'jsonPaginate',
        public int $defaultSize = 30,
        public int $maxResults = 30,
        public string $mode = self::MODE_LENGTH,
        public bool $recovered = true,
    ) {}

    /**
     * An empty bag (namespace never merged) yields package defaults with `recovered = false`.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self(recovered: false);
        }

        return new self(
            pageParameter: self::string($config, 'pagination_parameter', 'page'),
            numberParameter: self::string($config, 'number_parameter', 'number'),
            sizeParameter: self::string($config, 'size_parameter', 'size'),
            cursorParameter: self::string($config, 'cursor_parameter', 'cursor'),
            methodName: self::string($config, 'method_name', 'jsonPaginate'),
            defaultSize: self::int($config, 'default_size', 30),
            maxResults: self::int($config, 'max_results', 30),
            mode: self::mode($config),
        );
    }

    /** The `page[number]`-style bracketed parameter name for a member key. */
    public function bracket(string $member): string
    {
        return sprintf('%s[%s]', $this->pageParameter, $member);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function mode(array $config): string
    {
        if (($config['use_cursor_pagination'] ?? false) === true) {
            return self::MODE_CURSOR;
        }

        if (($config['use_simple_pagination'] ?? false) === true) {
            return self::MODE_SIMPLE;
        }

        return self::MODE_LENGTH;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function string(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function int(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
