<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Contracts\RouteFilter;
use Docuccino\Core\Support\PlainText;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Reads a document's `routes.filter` into the {@see RouteFilter} the route resolver asks once the
 * include/exclude wildcards have had their say.
 *
 * The key names a class rather than taking a predicate because a configuration FILE cannot hold one:
 * YAML has no form for a callable, and {@see ConfigFile::FLAGS} refuses the `!php/object` tag that
 * would smuggle one in. So the class-string is resolved out of the container, and a filter declares
 * its collaborators in its constructor — the shape `tags.mapper` already had. An interface bound in
 * the container is accepted for the same reason `tags.mapper` accepts one: the contract check is on
 * the instance the container hands back, not on the name.
 *
 * **A filter that cannot be applied stops the run.** There is no vague-but-true document to fall back
 * to here: the wildcards' output is a superset the author explicitly narrowed, so publishing it
 * states an API surface they contradicted, and publishing nothing states another. This is the reading
 * `export.targets` already gets ({@see ExportDiagnostics}) — a key that decides what goes in the
 * document is at least as load-bearing as one that decides where it goes — and unlike a schema
 * recovery, the reader can always act: they named a class, and it is missing, unbuildable, or not a
 * filter.
 *
 * @internal
 */
final readonly class ConfiguredRouteFilter
{
    public function __construct(private Container $container) {}

    /**
     * @param  array<string, mixed>  $routes  the document's `routes` bag
     *
     * @throws UnusableRouteFilterException
     */
    public function resolve(string $key, array $routes): ?RouteFilter
    {
        // The document key is the application's own name, the same as the class name below it already
        // escaped here. The console renderer's own escape does not cover it: this refusal is an
        // EXCEPTION, and its message is what a caller that lets it through prints. Escaped once, so
        // every refusal below carries it escaped rather than four of the five.
        $name = PlainText::of($key);
        $configured = $routes['filter'] ?? null;

        if ($configured === null) {
            return null;
        }

        if (! is_string($configured) || trim($configured) === '') {
            throw $this->refuse($name, sprintf(
                'documents.%s.routes.filter is %s rather than the name of a class implementing %s.',
                $name,
                self::describe($configured),
                RouteFilter::class,
            ));
        }

        $class = trim($configured);

        if (! class_exists($class) && ! interface_exists($class)) {
            throw $this->refuse($name, sprintf(
                "documents.%s.routes.filter names '%s', which is not an autoloadable class.",
                $name,
                PlainText::of($class),
            ));
        }

        try {
            $resolved = $this->container->make($class);
        } catch (Throwable $failure) {
            throw $this->refuse($name, sprintf(
                "documents.%s.routes.filter names '%s', which the container could not build: %s.",
                $name,
                PlainText::of($class),
                PlainText::of($failure->getMessage()),
            ), $failure);
        }

        if (! $resolved instanceof RouteFilter) {
            throw $this->refuse($name, sprintf(
                "documents.%s.routes.filter names '%s', which does not implement %s.",
                $name,
                PlainText::of($class),
                RouteFilter::class,
            ));
        }

        return $resolved;
    }

    /** `$name` arrives escaped, because it is the application's own document key. */
    private function refuse(string $name, string $message, ?Throwable $previous = null): UnusableRouteFilterException
    {
        return new UnusableRouteFilterException(
            new Diagnostic(
                severity: Severity::Error,
                code: 'config.route-filter-unusable',
                message: $message,
                help: sprintf('Point documents.%s.routes.filter at an autoloadable class implementing %s, or remove the key to document every route the include/exclude wildcards admit.', $name, RouteFilter::class),
            ),
            $previous,
        );
    }

    /** A value named the way a reader can compare it against what they wrote. */
    private static function describe(mixed $value): string
    {
        return is_string($value) ? "'".PlainText::of($value)."'" : get_debug_type($value);
    }
}
