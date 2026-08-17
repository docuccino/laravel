<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * The one query parameter a Laravel paginator reads to select a page: `page` for the length-aware and
 * simple paginators, `cursor` for the cursor one. Every integration documenting a Laravel-paginated
 * endpoint mints it here, so the two producers of a `page` cannot drift apart.
 *
 * There is deliberately no `per_page` beside it. `paginate()` takes its size from the call site or the
 * model's `$perPage`, never from the request, so an application that honours a page-size key wrote that
 * itself — only its own `#[QueryParameter]` can say so, and guessing one would name a key the endpoint
 * does not read.
 */
final class PaginatorPageParameter
{
    /**
     * Where each of Laravel's own terminals takes the key's name —
     * `paginate($perPage, $columns, $pageName)`, `cursorPaginate($perPage, $columns, $cursorName)`.
     *
     * @var array<string, string>
     */
    private const NAME_ARGUMENT = [
        'paginate' => 'pageName',
        'simplePaginate' => 'pageName',
        'cursorPaginate' => 'cursorName',
    ];

    /** The name argument's position in all three signatures. */
    private const NAME_POSITION = 2;

    /**
     * The page selector for a paginator kind, under `$name` where the call site renamed the key. An
     * unrecognised kind is treated as length-aware, as {@see PaginationEnvelope} treats it.
     */
    public static function for(?string $kind, ?string $name = null): QueryParameterSpec
    {
        if ($kind === 'cursor') {
            return new QueryParameterSpec(
                $name ?? 'cursor',
                ['type' => 'string'],
                'Opaque cursor for the next/previous page.',
            );
        }

        return new QueryParameterSpec(
            $name ?? 'page',
            ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            'Page number.',
        );
    }

    /**
     * The selector for the terminal a trace actually reached, read off that call's folded arguments —
     * positional ones under their index, named ones under their parameter name, each null where the
     * argument was written but would not fold. Null when the call site renamed the key to something
     * unfoldable: a guessed `page` there names a key the endpoint does not read. Only Laravel's own
     * terminals take the argument; a custom one forwards to `paginate($perPage)` and keeps the default.
     *
     * @param  array<array-key, string|int|float|bool|null>  $args
     */
    public static function forTerminal(?string $terminal, ?string $kind, array $args): ?QueryParameterSpec
    {
        $argument = self::NAME_ARGUMENT[$terminal ?? ''] ?? null;
        if ($argument === null) {
            return self::for($kind);
        }

        if (! array_key_exists($argument, $args) && ! array_key_exists(self::NAME_POSITION, $args)) {
            return self::for($kind);
        }

        $name = self::nameArg($args, $argument) ?? self::nameArg($args, self::NAME_POSITION);

        return $name === null ? null : self::for($kind, $name);
    }

    /** @param  array<array-key, string|int|float|bool|null>  $args */
    private static function nameArg(array $args, string|int $key): ?string
    {
        $value = $args[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
