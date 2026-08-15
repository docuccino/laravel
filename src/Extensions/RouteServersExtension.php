<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
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
 * get, and any variable the inherited path still names is carried over with it — an operation-level
 * server has to define every variable in its own URL.
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

        $url = $scheme.'://'.$host.$authority.$path;
        $server = ['url' => $url];

        $variables = $this->variables($host) + $this->inheritedVariables($inherited, $path);
        if ($variables !== []) {
            $server['variables'] = $variables;
        }

        // The host is written into the route, so nothing in the document pins it: a domain read out of
        // the environment publishes a URL only this machine can reach, exactly as an unpinned `app.url`
        // does, and the rule is the same one.
        $report = MachineDependentValue::forHost(
            self::PUBLISHED, $url, $context->route->signature($context->httpMethod()),
        );
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
     * @return array<string, array<string, string>>
     */
    private function variables(string $host): array
    {
        preg_match_all('/\{([^}]+)}/', $host, $matches);

        $variables = [];
        foreach ($matches[1] as $name) {
            $variables[$name] = [
                'default' => $name,
                'description' => sprintf('The "%s" segment of the host this operation is served from.', $name),
            ];
        }

        return $variables;
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
