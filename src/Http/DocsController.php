<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Http;

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\ViewerContext;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Support\JsonValue;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Runtime\DocumentCache;
use Docuccino\Laravel\Support\Paths;
use Docuccino\Laravel\Viewer\ViewerDrivers;
use Docuccino\Laravel\Watch\WatchSignal;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * The runtime viewer endpoints: the driver's HTML page, the `.json` spec (per `viewer.source`:
 * generate | artifact | cache), the driver's bundled asset, and the reload channel a
 * `docuccino:watch` session refreshes an open page through. Which driver renders is `viewer.driver`
 * ({@see ViewerDrivers}); every one of them arrives here, and all four endpoints go through
 * {@see authorize()} first — a configured `viewer.gate` ability, otherwise local environment only.
 * That ordering is the gate's whole guarantee: no driver and no channel can be reached without
 * passing it, because nothing but this controller ever calls one. The reload channel additionally
 * exists only while a watch session has published a signal, so nowhere a watcher isn't running
 * answers it at all.
 */
final class DocsController
{
    public function __construct(
        private readonly DocumentBuilder $builder,
        private readonly ViewerDrivers $drivers,
        private readonly WatchSignal $signal,
    ) {}

    public function show(string $document): Response
    {
        $config = $this->config($document);
        $this->authorize($config);

        $viewer = $this->drivers->for($config);
        $rendered = $viewer->render(new ViewerContext($config));

        if (is_string($rendered)) {
            return $this->response($config, $this->withReload($rendered, $config));
        }

        $this->warnUnreloadable($config, $viewer->name(), $rendered);

        return $this->response($config, $rendered);
    }

    /**
     * One `text/event-stream` event naming the documentation the last `docuccino:watch` rebuild
     * produced, and then the connection closes.
     *
     * Single-shot on purpose. `php artisan serve` is one process answering one request at a time, so
     * a held stream would wedge the very server the page is loaded from — and the reconnection
     * {@see ReloadScript} does costs one request every couple of seconds and blocks nothing.
     */
    public function reload(string $document): Response
    {
        $this->authorize($this->config($document));

        $token = $this->signal->token();
        abort_if($token === null, 404);

        return new Response("retry: 2000\nevent: reload\ndata: {$token}\n\n", 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, private',
            // Nginx buffers a proxied response by default, which holds an event until the buffer
            // fills — for a one-event body, forever.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Splice the live-reload subscriber into a viewer page, but only while a watch session has
     * published a signal: everywhere else the page is the static document it has always been, with
     * nothing polling in the background. Appended when the page has no `</body>` of its own, so a
     * viewer other than the bundled one still reloads.
     */
    private function withReload(string $html, DocumentConfig $config): string
    {
        $endpoint = $this->reloadEndpoint($config);

        if ($endpoint === null) {
            return $html;
        }

        $script = ReloadScript::html($endpoint);
        $position = strripos($html, '</body>');

        return $position === false ? $html.$script : substr_replace($html, $script, $position, 0);
    }

    /**
     * The channel an open page subscribes to, or null when there is nothing to subscribe to: no watch
     * session has published a signal, or the document has no viewer route to hang it off. Both the
     * splice and the warning about a page that misses it read this one answer, so the warning fires
     * exactly where the subscriber would have gone in.
     */
    private function reloadEndpoint(DocumentConfig $config): ?string
    {
        $route = $config->viewer['route'] ?? null;

        if ($this->signal->token() === null || ! is_string($route) || $route === '') {
            return null;
        }

        return url('/'.trim($route, '/').'/reload');
    }

    /**
     * A driver that builds its own response owns the page, live reload included — rewriting a body
     * this package did not construct could corrupt a response that isn't HTML. Saying nothing is what
     * costs: the terminal reports rebuild after rebuild while the page never moves. Only worth a word
     * while a watch session is actually running, and only for a response we could otherwise have
     * served; anything else is already a driver bug the response path reports.
     */
    private function warnUnreloadable(DocumentConfig $config, string $driver, mixed $rendered): void
    {
        $endpoint = $this->reloadEndpoint($config);

        if (! $rendered instanceof Response || $endpoint === null) {
            return;
        }

        Log::warning(sprintf(
            'Docuccino viewer "%s" is being watched, but driver "%s" returned its own response rather than HTML, so the live-reload subscriber was not spliced in and the open page will not refresh on a rebuild. Return HTML from the driver to get it, or subscribe your page to "%s" yourself.',
            $config->key,
            $driver,
            $endpoint,
        ));
    }

    public function spec(string $document, TypeEngine $engine, DocumentCache $cache): Response
    {
        $config = $this->config($document);
        $this->authorize($config);

        $source = $config->viewer['source'] ?? 'generate';
        $json = match ($source) {
            'artifact' => $this->fromArtifact($config, $engine),
            'cache' => $cache->get($document, $this->drivers->formatFor($config)) ?? $this->coldCacheFallback($config, $engine),
            default => $this->generate($config, $engine),
        };

        return new Response($json, 200, ['Content-Type' => 'application/json']);
    }

    private function generate(DocumentConfig $config, TypeEngine $engine): string
    {
        return $this->drivers->emitFor($config, $this->builder->build($config->key, $engine)->document);
    }

    /**
     * A cold cache — or one warmed for a format this viewer is no longer served — generates rather
     * than serving an empty document, and warns so the missed `docuccino:cache` warm-up is visible
     * instead of silently degrading.
     */
    private function coldCacheFallback(DocumentConfig $config, TypeEngine $engine): string
    {
        Log::warning(sprintf(
            'Docuccino viewer "%s" is configured with source=cache but no cached payload matches the format it is served; generating on the fly. Run `docuccino:cache` to warm it.',
            $config->key,
        ));

        return $this->generate($config, $engine);
    }

    /**
     * One of the active driver's own assets. The driver publishes the name → file map, so a name that
     * driver does not publish is a 404 rather than a path this route resolves — switching drivers
     * closes the previous one's asset with it.
     */
    public function asset(Request $request): Response
    {
        // Read by NAME, not by signature position: this is the one viewer route with a URI parameter,
        // and Laravel appends a route's `defaults` after its URI parameters, so positional binding
        // would hand `$document` the asset name.
        $config = $this->config($this->routeParameter($request, 'document'));
        $this->authorize($config);

        $path = $this->drivers->asset($config, $this->routeParameter($request, 'asset'));
        if ($path === null) {
            abort(404);
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            // Serving a blank viewer silently makes "the docs page is empty" undiagnosable; log it.
            Log::warning(sprintf('Docuccino viewer asset could not be read at "%s"; serving an empty body.', $path));
            $contents = '';
        }

        return new Response($contents, 200, [
            'Content-Type' => 'application/javascript',
            // The bundle only changes on package upgrade, so cache it immutably and skip re-reading
            // megabytes on every viewer load.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * A driver renders `mixed` — the contract is framework-agnostic — so this adapter accepts the two
     * things it can serve: HTML, or a response the driver built itself. Anything else is a driver bug,
     * and one that would otherwise show up as a blank page with no explanation anywhere.
     */
    private function response(DocumentConfig $config, mixed $rendered): Response
    {
        if ($rendered instanceof Response) {
            return $rendered;
        }

        if (is_string($rendered)) {
            return new Response($rendered, 200, ['Content-Type' => 'text/html']);
        }

        Log::warning(sprintf(
            'Docuccino viewer "%s" rendered a %s; a driver must return HTML or an Illuminate response. Serving an empty page.',
            $config->key,
            get_debug_type($rendered),
        ));

        return new Response('', 200, ['Content-Type' => 'text/html']);
    }

    /** A route parameter by name, empty when it is absent or not a string. */
    private function routeParameter(Request $request, string $name): string
    {
        $value = $request->route($name);

        return is_string($value) ? $value : '';
    }

    private function config(string $document): DocumentConfig
    {
        abort_unless($this->builder->hasDocument($document), 404);

        return $this->builder->config($document);
    }

    private function authorize(DocumentConfig $config): void
    {
        $gate = $config->viewer['gate'] ?? null;

        $allowed = is_string($gate) && $gate !== ''
            ? Gate::allows($gate)
            : app()->environment('local') === true;

        abort_unless($allowed, 403);
    }

    private function fromArtifact(DocumentConfig $config, TypeEngine $engine): string
    {
        $target = ViewerArtifact::of($config);
        if ($target === null) {
            // Every configured target is something the viewer cannot serve (a Postman collection, a
            // YAML file). Generating beats serving bytes the browser will choke on, and the warning
            // makes the mismatch visible instead of leaving an empty page to diagnose.
            Log::warning(sprintf(
                'Docuccino viewer "%s" is configured with source=artifact but no export target holds JSON it can serve; generating on the fly.',
                $config->key,
            ));

            return $this->generate($config, $engine);
        }

        $absolute = Paths::absolute($target->path, base_path());
        $contents = @file_get_contents($absolute);
        if ($contents === false) {
            // Unlike the branch above, this one does NOT generate: a target that could be served but
            // isn't there yet is the one case `artifact` exists to make impossible — the source is
            // chosen so no request ever re-analyses, and an unshipped file must not turn a viewer hit
            // into a build on a production box. So the body stays empty and the log carries the whole
            // diagnosis, since "the docs page is empty" is otherwise undiagnosable.
            Log::warning(sprintf(
                'Docuccino viewer "%s" is configured with source=artifact but "%s" could not be read; serving an empty body. Run `docuccino:export` and ship the file with your release.',
                $config->key,
                $absolute,
            ));

            return '';
        }

        // A UIR artifact (the `uir` field) is re-emitted as OAS — the viewer expects OAS, and a UIR's
        // internal x-docuccino provenance must never reach the browser. Plain OpenAPI streams through
        // untouched, so an artifact exported for a specific viewer stays that viewer's business.
        //
        // Through the shared reader ({@see JsonValue}), because this re-emits: an associative decode
        // reads an `example: {}` the export wrote back as `[]`, and the viewer would then answer with
        // a different document from the file beside it. Bytes that are not JSON at all stream on, the
        // same as a plain OpenAPI export does.
        try {
            $decoded = JsonValue::decode($contents);
        } catch (JsonException) {
            return $contents;
        }

        if (is_array($decoded) && isset($decoded['uir'])) {
            /** @var array<string, mixed> $decoded */
            return $this->drivers->emitFor($config, UirDocument::fromArray($decoded));
        }

        return $contents;
    }
}
