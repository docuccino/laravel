<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * The one query parameter a Laravel paginator reads to select a page: `page` for the length-aware and
 * simple paginators, `cursor` for the cursor one. Every integration documenting a Laravel-paginated
 * endpoint mints it here, so the two producers of a `page` cannot drift apart.
 *
 * A page SIZE sits beside it only where one was PROVEN. `paginate()` takes its size from the call site or
 * the model's `$perPage`, never from the request, so a size key is never assumed: an application that
 * honours one wrote that itself, and {@see RequestPageSizeReader} documents the key only when it followed
 * the paginator's size argument back to a read off the request. No read, no parameter — a guessed one
 * would name a key the endpoint ignores.
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
     * The page-size selector for a key the trace proved the endpoint reads. Minted here so this and the
     * resource-collection producer cannot disagree about a size key any more than they can about a page one.
     *
     * The schema states the type and nothing else: an application clamps an out-of-range size to its
     * nearest bound far more often than it rejects one, and a `minimum`/`maximum` recovered from a clamp
     * would tell a consumer their value is invalid when it is merely adjusted. A `default` rides along only
     * where the read itself was written with a literal fallback.
     */
    public static function size(RequestPageSizeKey $recovered): QueryParameterSpec
    {
        $schema = ['type' => 'integer'];
        if ($recovered->default !== null) {
            $schema['default'] = $recovered->default;
        }

        return new QueryParameterSpec($recovered->key, $schema, 'Number of items per page.');
    }

    /**
     * The selector for the terminal a trace actually reached, read off that call's folded arguments
     * ({@see FoldedArguments}). Null when the call site renamed the key to something unfoldable, and null
     * again when the call carries a spread and the name argument may be in there: a guessed `page` names a
     * key the endpoint does not read. Only Laravel's own terminals take the argument; a custom one
     * forwards to `paginate($perPage)` and keeps the default.
     *
     * @param  array<array-key, string|int|float|bool|null>|null  $args
     */
    public static function forTerminal(?string $terminal, ?string $kind, ?array $args): ?QueryParameterSpec
    {
        $argument = self::NAME_ARGUMENT[$terminal ?? ''] ?? null;
        if ($argument === null) {
            return self::for($kind);
        }

        if ($args === null) {
            return null;
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
