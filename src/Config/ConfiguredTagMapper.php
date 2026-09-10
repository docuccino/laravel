<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Contracts\TagMapper;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Laravel\Tags\PrefixTagMapper;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Reads a document's `tags` bag into the {@see TagMapper} every published tag goes through: a
 * container-resolved `tags.mapper`, else a {@see PrefixTagMapper} over `tags.map`, else nothing and
 * the tags stay as the code wrote them.
 *
 * The key names a class for the reason `routes.filter` does ({@see ConfiguredRouteFilter}): a
 * configuration FILE cannot hold a callable, so the string is resolved out of the container and a
 * mapper declares its collaborators in its constructor. An interface bound in the container is
 * accepted too — the contract check is on the instance the container hands back, not on the name.
 *
 * **A mapper that cannot be produced degrades to no mapper**, where an unusable route filter stops the
 * run. The two keys are not the same kind of key: a filter decides what the document CONTAINS, so
 * publishing without it states a surface the author narrowed on purpose, while a mapper only renames
 * what is already there — unmapped tags are the names the controllers and `#[Group]` attributes wrote,
 * which is a true document rather than a narrower one. So this reports and carries on.
 *
 * @internal
 */
final readonly class ConfiguredTagMapper
{
    public function __construct(private Container $container) {}

    /**
     * @param  array<string, mixed>  $tags  the document's `tags` bag
     */
    public function resolve(array $tags): ?TagMapper
    {
        $named = self::named($tags);

        if ($named === null) {
            // A `mapper` that names nothing usable does NOT fall through to `map`: reporting the key and
            // then quietly honouring a different one would publish a third taxonomy, neither the one the
            // author configured nor the one their code wrote.
            $map = array_key_exists('mapper', $tags) ? [] : Hydrate::stringMap($tags['map'] ?? null);

            return $map === [] ? null : new PrefixTagMapper($map);
        }

        try {
            $resolved = $this->container->make($named);
        } catch (Throwable) {
            return null;
        }

        return $resolved instanceof TagMapper ? $resolved : null;
    }

    /**
     * The one line a document owes its author when `tags.mapper` named a mapper and got none — the
     * message says which of the four states it is in, so the fix is the one the reader can make.
     *
     * Asked of the RESOLVED mapper rather than resolving a second time, which is what keeps this and
     * {@see resolve()} one answer: null with a name in the bag is the whole condition, and the reason
     * beside it is read off the name itself. A container that threw is therefore named and not quoted;
     * everything the author can act on is in which of the four lines they get.
     *
     * @param  array<string, mixed>  $tags  the document's `tags` bag
     */
    public static function diagnose(string $key, array $tags, ?TagMapper $resolved): ?Diagnostic
    {
        if (! array_key_exists('mapper', $tags)) {
            return null;
        }

        $named = self::named($tags);

        if ($named === null) {
            return self::dropped($key, sprintf(
                'documents.%s.tags.mapper is %s rather than the name of a class implementing %s',
                $key,
                self::describe($tags['mapper']),
                TagMapper::class,
            ));
        }

        if ($resolved !== null) {
            return null;
        }

        return self::dropped($key, sprintf(
            "documents.%s.tags.mapper names '%s', which %s",
            $key,
            $named,
            self::why($named),
        ));
    }

    /**
     * The name `tags.mapper` holds, trimmed — or null when it holds nothing that could name a class.
     *
     * @param  array<string, mixed>  $tags
     */
    private static function named(array $tags): ?string
    {
        $mapper = $tags['mapper'] ?? null;

        return is_string($mapper) && trim($mapper) !== '' ? trim($mapper) : null;
    }

    /**
     * Which of the three ways a named mapper failed, read off the name. The container is the only thing
     * left once the name loads and implements the contract, so the last branch says so.
     */
    private static function why(string $named): string
    {
        if (! class_exists($named) && ! interface_exists($named)) {
            return 'is neither an autoloadable class nor a name the container has bound';
        }

        return is_a($named, TagMapper::class, true)
            ? 'the container could not build'
            : 'does not implement '.TagMapper::class;
    }

    /**
     * A WARNING rather than an info: the tags a document publishes are its taxonomy, and this one is
     * not the taxonomy the author wrote. Nothing about the document is invalid, so it is not an error.
     */
    private static function dropped(string $key, string $what): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'config.tag-mapper-unusable',
            message: $what.' — the document publishes its tags exactly as your controllers and #[Group] attributes wrote them.',
            help: sprintf('Point documents.%s.tags.mapper at an autoloadable class implementing %s, or remove the key. A mapper the container builds can take its own constructor dependencies; one it cannot is dropped rather than stopping the run, because unmapped tags are still the truth.', $key, TagMapper::class),
        );
    }

    /** A value named the way a reader can compare it against what they wrote. */
    private static function describe(mixed $value): string
    {
        return is_string($value) ? "'".$value."'" : get_debug_type($value);
    }
}
