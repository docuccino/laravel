<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Support\PortablePattern;
use Docuccino\Laravel\Routing\RouteConstraints;
use Docuccino\Laravel\Routing\RouteTemplate;
use Docuccino\Laravel\Support\MachineDependentValue;

/**
 * Gives a host-bound route (`Route::domain('admin.example.com')->group(...)`) an operation-level
 * `servers` entry naming the host it answers on. OpenAPI has no per-operation host and `servers` is
 * the only member that carries one, so without this a generated client calls an admin or tenant route
 * on the document's default host.
 *
 * Binding a host swaps the HOST out of the document's server URL and nothing else, so the scheme, the
 * port and the base PATH all come from that URL — operation-level `servers` overrides the root array
 * outright, so dropping the `/v1` off `https://api.example.com/v1` would point a generated client at a
 * URL that does not exist. A templated host (`{tenant}.example.com`) becomes a server variable
 * defaulting to the placeholder's own name, which is as close to a value as the routes can honestly
 * get — unless the route constrains it to a closed set, which is then its `enum` ({@see variables()}).
 * A route's `->defaults()` is not published for a host segment: a host the router matches carries
 * the segment, so the default is not what the action receives. Any variable the inherited path still
 * names is carried over with it — an operation-level server has to define every variable in its own URL.
 */
final class RouteServersExtension implements OperationExtension
{
    /** What a machine-dependent-value report names, since the operation's own slot isn't settled yet. */
    private const PUBLISHED = "The operation's host-bound server URL";

    public function phase(): OperationPhase
    {
        return OperationPhase::Overrides;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $domain = $context->route->domain;
        if ($domain === null || $domain === '') {
            return;
        }

        // `{tenant?}` is the same host segment as `{tenant}` once it reaches a URL.
        $host = preg_replace('/\{([^}]+)\?}/', '{$1}', $domain) ?? $domain;

        [$scheme, $authority, $path, $inherited] = $this->base($context);

        // `Route::domain()` strips a scheme and nothing else, so whatever the route was bound to
        // reaches here intact — userinfo included, where the domain came from an environment value that
        // carried some. The URL publishes without it, which is the same rule the flow URLs and the
        // derived server obey; the host's own identity is untouched, so nothing else moves.
        $signature = $context->route->signature($context->httpMethod());
        $url = $scheme.'://'.$host.$authority.$path;

        $credentials = MachineDependentValue::forCredentials(self::PUBLISHED, $url, "the route's own domain", $signature);
        if ($credentials !== null) {
            $context->components->addDiagnostic($credentials);
            $url = MachineDependentValue::withoutCredentials($url);
        }

        $server = ['url' => $url];

        $variables = $this->variables($host, $context) + $this->inheritedVariables($inherited, $path);
        if ($variables !== []) {
            $server['variables'] = $variables;
        }

        // The host is written into the route, so nothing in the document pins it: a domain read out of
        // the environment publishes a URL only this machine can reach, exactly as an unpinned `app.url`
        // does, and the rule is the same one.
        $report = MachineDependentValue::forHost(self::PUBLISHED, $url, $signature);
        if ($report !== null) {
            $context->components->addDiagnostic($report);
        }

        $operation->set('servers', [$server], Contribution::fallback());
    }

    /**
     * Everything but the host of the document's server URL: its scheme, the `:port` that follows the
     * host, the base path, and the variables it declares. The first server that states a scheme decides
     * — a relative or unparseable url is not a base anything can hang off — and a document that states
     * none says https over the bare host.
     *
     * @return array{string, string, string, array<array-key, mixed>}
     */
    private function base(RouteContext $context): array
    {
        foreach ($context->document->servers as $server) {
            $url = $server['url'] ?? null;
            $parts = is_string($url) ? parse_url($url) : false;
            $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;

            if (! is_array($parts) || ! is_string($scheme) || $scheme === '') {
                continue;
            }

            $port = $parts['port'] ?? null;
            $variables = $server['variables'] ?? null;

            return [
                $scheme,
                $port === null ? '' : ':'.$port,
                // A trailing slash is the empty base path spelled out, and `https://host/` is not a
                // legal OAS server url anyway.
                rtrim($parts['path'] ?? '', '/'),
                is_array($variables) ? $variables : [],
            ];
        }

        return ['https', '', '', []];
    }

    /**
     * One variable per host segment. A segment the route constrains to literals (`whereIn()`) is that
     * closed set, defaulting to its first value because a default must be one of them; any other
     * constraint has no keyword in a Server Variable Object, so its description states it. OpenAPI
     * requires a default, and nothing else names a host the route answers on, so the placeholder's own
     * name stays one — said to be no value at all where the constraint refuses it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function variables(string $host, RouteContext $context): array
    {
        $variables = [];
        foreach (RouteTemplate::parameters($host) as $name) {
            $expression = $context->hostParameterConstraints[$name] ?? null;
            $values = $expression === null ? null : PortablePattern::literals($expression);

            $sentences = [sprintf('The "%s" segment of the host this operation is served from.', $name)];
            if ($expression !== null && $values === null) {
                $sentences[] = self::constraintNote($expression);
                // The router matches a host ignoring case.
                if (@preg_match('{^(?:'.$expression.')$}sDiu', $name) === 0) {
                    $sentences[] = 'The default only names the segment, and is not a value it accepts.';
                }
            }

            $variables[$name] = [
                ...($values === null ? [] : ['enum' => $values]),
                'default' => $values[0] ?? $name,
                'description' => implode(' ', array_filter($sentences, static fn (?string $sentence): bool => $sentence !== null)),
            ];
        }

        return $variables;
    }

    /**
     * The sentence stating a constraint no keyword can, or null where none states it truly. The router
     * matches a host ignoring case, and a pattern it reads differently from ECMA-262 says nothing.
     */
    private static function constraintNote(string $expression): ?string
    {
        if (in_array($expression, RouteConstraints::CATCH_ALL, true)) {
            return null;
        }

        $format = RouteConstraints::format($expression);
        if ($format !== null) {
            return sprintf('It is a %s.', strtoupper($format));
        }

        $pattern = RouteConstraints::pattern($expression);

        return $pattern === null ? null : sprintf('It matches the pattern `%s`, ignoring case.', $pattern);
    }

    /**
     * The document server's own variable definitions, restricted to the ones the inherited path still
     * names. Anything else belongs to the host we just replaced.
     *
     * @param  array<array-key, mixed>  $declared
     * @return array<array-key, mixed>
     */
    private function inheritedVariables(array $declared, string $path): array
    {
        return array_filter(
            $declared,
            static fn (int|string $name): bool => str_contains($path, '{'.$name.'}'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
