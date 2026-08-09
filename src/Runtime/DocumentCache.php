<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Runtime;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;

/**
 * The runtime document cache: an assembled document's served payload in a Laravel cache store, keyed by
 * document, so `viewer.source: cache` doesn't rebuild per request. Not to be confused with the fragment
 * cache (design §10), which keys per operation on the filesystem for incremental builds.
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
