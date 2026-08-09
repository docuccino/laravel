<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Runtime;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;

/**
 * The runtime document cache (design §Multiple documents / ops parity `docuccino:cache`): stores an
 * assembled document's served payload in a configured Laravel cache store, keyed by document, so
 * the runtime endpoint can serve `viewer.source: cache` without rebuilding on every request. This
 * is distinct from the fragment cache (design §10) — that keys per operation on the filesystem for
 * incremental builds; this keys per whole document in the app cache for serving.
 */
final readonly class DocumentCache
{
    public function __construct(
        private Factory $cache,
        private ?string $store = null,
    ) {}

    public function key(string $document): string
    {
        return 'docuccino:document:'.$document;
    }

    public function put(string $document, string $payload): void
    {
        $this->repository()->forever($this->key($document), $payload);
    }

    public function get(string $document): ?string
    {
        $value = $this->repository()->get($this->key($document));

        return is_string($value) ? $value : null;
    }

    public function forget(string $document): void
    {
        $this->repository()->forget($this->key($document));
    }

    private function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
