<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

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
 * **A filter that cannot be applied stops the run.** There is no vague-but-true document to fall back
 * to here: the wildcards' output is a superset the author explicitly narrowed, so publishing it
 * states an API surface they contradicted, and publishing nothing states another. This is the reading
 * `export.targets` already gets ({@see ExportDiagnostics}) — a key that decides what goes in the
 * document is at least as load-bearing as one that decides where it goes — and unlike a schema
 * recovery, the reader can always act: they named a class, and it is missing, unbuildable, or not a
 * filter.
 *
 * The removed `routes.closure` refuses on the same terms, which is why it needs no deprecation
 * window: a window exists to stop a silent change, and nothing here is silent. A closure in config is
 * not serialisable, so an application that configured one could never run `config:cache` — the key
 * was broken in the format it was written in, before ever reaching one it cannot be expressed in.
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
        $this->refuseClosure($key, $routes['closure'] ?? null);

        return $this->fromClass($key, $routes['filter'] ?? null);
    }

    /**
     * `routes.filter` names a class the container builds, so a filter takes its collaborators in the
     * constructor. An interface bound in the container is accepted for the same reason `tags.mapper`
     * accepts one — the contract check is on the instance the container hands back, not on the name.
     */
    private function fromClass(string $key, mixed $configured): ?RouteFilter
    {
        if ($configured === null) {
            return null;
        }

        if (! is_string($configured) || trim($configured) === '') {
            throw $this->refuse($key, sprintf(
                'documents.%s.routes.filter is %s rather than the name of a class implementing %s.',
                $key,
                self::describe($configured),
                RouteFilter::class,
            ));
        }

        $class = trim($configured);

        if (! class_exists($class) && ! interface_exists($class)) {
            throw $this->refuse($key, sprintf(
                "documents.%s.routes.filter names '%s', which is not an autoloadable class.",
                $key,
                PlainText::of($class),
            ));
        }

        try {
            $resolved = $this->container->make($class);
        } catch (Throwable $failure) {
            throw $this->refuse($key, sprintf(
                "documents.%s.routes.filter names '%s', which the container could not build: %s.",
                $key,
                PlainText::of($class),
                PlainText::of($failure->getMessage()),
            ), $failure);
        }

        if (! $resolved instanceof RouteFilter) {
            throw $this->refuse($key, sprintf(
                "documents.%s.routes.filter names '%s', which does not implement %s.",
                $key,
                PlainText::of($class),
                RouteFilter::class,
            ));
        }

        return $resolved;
    }

    /**
     * The removed key. Asked by VALUE and not by presence: a `null` under it is a line left behind by
     * a config published before the key went, where there is nothing to migrate and nothing to act on,
     * and a build that refused over it would be refusing over a key its owner never used.
     *
     * Any other value was written to narrow the route set, so it gets the same refusal an unusable
     * filter gets rather than being ignored — ignoring it publishes exactly the routes it was there to
     * keep out.
     *
     * @throws UnusableRouteFilterException
     */
    private function refuseClosure(string $key, mixed $configured): void
    {
        if ($configured === null) {
            return;
        }

        throw new UnusableRouteFilterException(
            new Diagnostic(
                severity: Severity::Error,
                code: 'config.route-closure-removed',
                message: sprintf(
                    'documents.%s.routes.closure is set to %s, and that key is no longer read.',
                    $key,
                    self::describe($configured),
                ),
                help: sprintf(
                    'Move the predicate into a class implementing %s and name it under documents.%s.routes.filter, which is resolved from the container so it can take the dependencies the closure closed over. A closure could not be serialized, so an application configuring one could never run `php artisan config:cache`. The build refuses rather than ignoring the key, because ignoring it would document every route the include/exclude wildcards admit.',
                    RouteFilter::class,
                    $key,
                ),
            ),
        );
    }

    private function refuse(string $key, string $message, ?Throwable $previous = null): UnusableRouteFilterException
    {
        return new UnusableRouteFilterException(
            new Diagnostic(
                severity: Severity::Error,
                code: 'config.route-filter-unusable',
                message: $message,
                help: sprintf('Point documents.%s.routes.filter at an autoloadable class implementing %s, or remove the key to document every route the include/exclude wildcards admit.', $key, RouteFilter::class),
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
