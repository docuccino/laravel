<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Support\Hydrate;

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
