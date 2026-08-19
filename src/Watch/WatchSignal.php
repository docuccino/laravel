<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Watch;

use Docuccino\Core\Support\AtomicFile;
use Docuccino\Core\Support\GeneratedDirectory;

/**
 * The one value a running `docuccino:watch` and the viewer's reload endpoint share: a token naming
 * the documentation the last rebuild produced. It lives on disk because those are two processes —
 * the watcher in your terminal and whatever is serving the viewer — and it is also the endpoint's
 * ON switch: no signal, no reload endpoint.
 *
 * The token is a digest of what was built, never a timestamp or a counter, so a rebuild that changed
 * no byte leaves an open viewer alone. Anything that isn't one is read as no signal at all, which is
 * what keeps a file the endpoint echoes back from carrying anything but a hash.
 *
 * @internal
 */
final readonly class WatchSignal
{
    public function __construct(private string $path) {}

    /** Atomically, so a reader never catches a half-written token. */
    public function publish(string $token): void
    {
        GeneratedDirectory::ensure(dirname($this->path));

        AtomicFile::write($this->path, $token);
    }

    /** The published token, or null when no watch session has published one this build recognises. */
    public function token(): ?string
    {
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            return null;
        }

        $token = trim($raw);

        return preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? $token : null;
    }

    /** End of session: the endpoint goes away again. */
    public function clear(): void
    {
        @unlink($this->path);
    }
}
