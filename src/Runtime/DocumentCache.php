<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Runtime;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;

/**
 * The runtime document cache: an assembled document's served payload in a Laravel cache store, keyed by
 * document, so `viewer.source: cache` doesn't rebuild per request. Not to be confused with the fragment
 * cache (design §10), which keys per operation on the filesystem for incremental builds.
 *
 * A payload is emitted for whichever OpenAPI version the document's viewer implements, so it is only
 * valid for the format it was written in: an entry records that format beside the bytes and a read for
 * a different one is a MISS. Without that, switching drivers would leave the endpoint serving the
 * previous driver's version indefinitely — the entry is stored forever.
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

    public function put(string $document, string $payload, string $format): void
    {
        $this->repository()->forever(
            $this->key($document),
            json_encode(['format' => $format, 'payload' => $payload], JSON_THROW_ON_ERROR),
        );
    }

    public function get(string $document, string $format): ?string
    {
        $value = $this->repository()->get($this->key($document));
        if (! is_string($value)) {
            return null;
        }

        /** @var mixed $entry */
        $entry = json_decode($value, true);
        if (! is_array($entry) || ($entry['format'] ?? null) !== $format) {
            return null;
        }

        $payload = $entry['payload'] ?? null;

        return is_string($payload) ? $payload : null;
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
