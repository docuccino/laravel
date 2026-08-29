<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Versioning\VersionOrder;

/**
 * The `docuccino.documents` bag: which documents this application configures, and the raw entry for
 * one of them. Every reader of the key set comes here, so the set a build resolves and the set an
 * `#[InDocs]` key is judged against cannot disagree.
 *
 * Read on every call rather than memoised — the key set is ordinary test and command input, and a
 * cached one would answer a build with the configuration of the build before it.
 *
 * @internal
 */
final class ConfiguredDocuments
{
    /**
     * The bag as configured, keyed by document key.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        /** @var array<string, mixed> $documents */
        $documents = (array) config('docuccino.documents', []);

        return $documents;
    }

    /**
     * The configured keys, in config declaration order — the order they are written in is the order
     * their author reads them in, so a message listing them lists them that way.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(
            static fn (int|string $key): string => (string) $key,
            array_keys($this->all()),
        );
    }

    /**
     * Every version the application configures, sorted — the closed set a version header enumerates, read
     * off the documents themselves so there is no second list to keep in step with them. A document that
     * declares `api_version` and states no version of its own contributes nothing: its own build says so.
     *
     * Sorted by {@see VersionOrder}, never bytewise: `1.10.0` before `1.9.0` is the reading the whole of
     * versioning exists to replace, and publishing it in the enum a consumer reads would be that reading
     * shipped in the artifact.
     *
     * @return list<string>
     */
    public function apiVersions(): array
    {
        $versions = [];
        foreach ($this->all() as $entry) {
            // What makes a document a version, and what its version IS, are DocumentConfig's rules; asking
            // it is what keeps the enum and the document that publishes it saying the same thing.
            $version = DocumentConfig::statedVersion(Hydrate::map($entry));

            if ($version !== null) {
                $versions[$version] = true;
            }
        }

        return VersionOrder::sorted(array_keys($versions));
    }

    /** Whether `$key` names a configured document. */
    public function has(string $key): bool
    {
        return is_array($this->all()[$key] ?? null);
    }

    /**
     * One document's raw configuration, empty when it names none.
     *
     * @return array<string, mixed>
     */
    public function raw(string $key): array
    {
        return Hydrate::map($this->all()[$key] ?? null);
    }
}
