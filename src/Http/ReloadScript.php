<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Http;

use JsonException;

/**
 * The live-reload subscriber spliced into the viewer page while a `docuccino:watch` session is
 * running. It subscribes to the reload endpoint, remembers the first token it is told and reloads
 * the page when a later one differs — so a rebuild that changed nothing leaves the page alone.
 *
 * It handles reconnection itself instead of leaving it to `EventSource`, because the endpoint sends
 * one event and closes ({@see DocsController::reload()}): a fixed wait after each close, doubling on
 * an error and reset by the next event, so a stopped watcher or a throttled route backs off to
 * something a dev server never notices rather than hammering it.
 *
 * @internal
 */
final class ReloadScript
{
    /** @throws JsonException never in practice — the argument is a URL this package built. */
    public static function html(string $endpoint): string
    {
        $url = json_encode($endpoint, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        return <<<HTML
            <script>
            (function () {
                var url = {$url};
                var token = null;
                var wait = 2000;

                function open() {
                    var source = new EventSource(url);

                    source.addEventListener('reload', function (event) {
                        wait = 2000;
                        if (token === null) {
                            token = event.data;
                        } else if (event.data !== token) {
                            window.location.reload();
                        }
                    });

                    source.onerror = function () {
                        source.close();
                        window.setTimeout(open, wait);
                        wait = Math.min(wait * 2, 30000);
                    };
                }

                open();
            })();
            </script>
            HTML;
    }
}
