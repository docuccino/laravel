<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Viewer;

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Contracts\Viewer;
use Docuccino\Core\Extensions\Contracts\ViewerAssets;
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
