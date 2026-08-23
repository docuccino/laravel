<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Viewer;

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\EmitReport;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\ReportingEmitter;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Contracts\Viewer;
use Docuccino\Core\Extensions\Contracts\ViewerAssets;
use Docuccino\Core\Extensions\Contracts\ViewerSpecVersion;
use Docuccino\Laravel\Registry\ConfigExtensions;
use Docuccino\Laravel\Registry\ExtensionRegistry;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * Turns a document's `viewer.driver` string into the {@see Viewer} that renders it, over the built-in
 * drivers plus anything registered with `Docuccino::extend()`.
 *
 * The lookup happens per request, never at boot: the registry is late-bound, so asking earlier would
 * miss a driver registered by a provider that booted after this one.
 *
 * @internal
 */
final class ViewerDrivers
{
    public function __construct(
        private readonly ExtensionRegistry $registry,
        private readonly Container $container,
    ) {}

    /**
     * The viewer for this document. An unknown `viewer.driver` degrades to the default and logs which
     * names exist, rather than fataling on a page whose whole job is to be readable — the request
     * still gets a working reference, and the log says why it is not the one that was asked for.
     */
    public function for(DocumentConfig $config): Viewer
    {
        $drivers = $this->all();
        $requested = $config->viewer['driver'] ?? null;
        $name = is_string($requested) && $requested !== '' ? $requested : DefaultViewers::DEFAULT;

        $viewer = $drivers[$name] ?? null;
        if ($viewer !== null) {
            return $viewer;
        }

        Log::warning(sprintf(
            'Docuccino viewer "%s" names driver "%s", which nothing registers; rendering with "%s" instead. Registered drivers: %s.',
            $config->key,
            $name,
            DefaultViewers::DEFAULT,
            implode(', ', array_keys($drivers)),
        ));

        return $drivers[DefaultViewers::DEFAULT] ?? new ScalarViewer;
    }

    /**
     * The emitter matching the OpenAPI version the document's viewer implements
     * ({@see ViewerSpecVersion}); a driver that declares nothing gets the newest format. Every
     * endpoint that feeds a viewer — the spec route, a UIR-artifact re-emit, the runtime cache —
     * picks its emitter here, so they cannot disagree about what a driver is served.
     */
    public function emitterFor(DocumentConfig $config): ReportingEmitter
    {
        $viewer = $this->for($config);
        $version = $viewer instanceof ViewerSpecVersion ? $viewer->specVersion() : null;

        return match ($version) {
            '3.0' => new OpenApi30DownlevelEmitter,
            '3.1' => new OpenApi31DownlevelEmitter,
            default => new OpenApi32Emitter,
        };
    }

    /** The format id the document's viewer is served — what a cached payload is only valid for. */
    public function formatFor(DocumentConfig $config): string
    {
        return $this->emitterFor($config)->format();
    }

    /**
     * Emit a document for its viewer, and say what the format could not carry. Every serve seam goes
     * through here: a downlevel emitter silently drops what its minor has no room for — a query-method
     * operation, additional operations, tag metadata — and a consumer reading the page cannot tell.
     * The report is the author's business, so it goes to the log rather than into the document.
     */
    public function emitFor(DocumentConfig $config, UirDocument $document): string
    {
        $emitter = $this->emitterFor($config);
        $result = $emitter->emitWithReport($document, new EmitOptions);

        if (! $result->report->isEmpty()) {
            $this->logLoss($config, $emitter->format(), $result->report);
        }

        return $result->output;
    }

    private function logLoss(DocumentConfig $config, string $format, EmitReport $report): void
    {
        $codes = [];
        foreach ($report->diagnostics as $diagnostic) {
            $codes[$diagnostic->code] = true;
        }

        $message = sprintf(
            'Docuccino viewer "%s" is served %s, which could not carry everything the document holds: %s. Only the served page loses it — `docuccino:export` writes the full document.',
            $config->key,
            $format,
            implode(', ', array_keys($codes)),
        );

        // A warning in the report means something a client would have relied on is gone; anything
        // quieter is a note about what the older minor cannot express.
        $report->warnings() === [] ? Log::info($message) : Log::warning($message);
    }

    /**
     * The file a document's viewer publishes under $asset, or null when it publishes none by that
     * name. The driver's own map is the allow-list, so a name arriving over HTTP can only ever reach
     * a file that driver shipped.
     */
    public function asset(DocumentConfig $config, string $asset): ?string
    {
        $viewer = $this->for($config);

        return $viewer instanceof ViewerAssets ? ($viewer->assets()[$asset] ?? null) : null;
    }

    /**
     * @return array<string, Viewer>
     */
    private function all(): array
    {
        [$configExtensions] = ConfigExtensions::read();

        return $this->registry->viewers($this->container, DefaultViewers::all(), $configExtensions);
    }
}
