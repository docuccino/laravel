<?php

declare(strict_types=1);

use Docuccino\Laravel\Config\DocumentConfigFactory;

/*
 * `info.description.file` names a file whose CONTENTS are emitted. The raw config bag keeps the path,
 * so the fingerprint that describes the document's configuration has to see what the file says — not
 * just what it is called. Nothing goes stale either way (the description is document-level, and
 * `contentHash` covers the finished bytes), but a fingerprint that under-reports says two configurations
 * are the same when they emit different prose.
 */

/** The `default` document's config fingerprint, with `info.description.file` pointed at $path. */
function fingerprintForDescription(string $path): string
{
    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $raw['info'] = ['title' => 'Fingerprint', 'version' => '1.0.0', 'description' => ['file' => $path]];

    return app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton')->hash();
}

it('moves the config fingerprint when the described file changes underneath it', function (): void {
    $path = base_path('docuccino-fingerprint.md');

    file_put_contents($path, "First prose.\n");
    $first = fingerprintForDescription($path);

    file_put_contents($path, "Second prose.\n");
    $second = fingerprintForDescription($path);

    // …and the same contents twice still agree, so the row is not just "every call differs".
    file_put_contents($path, "First prose.\n");
    $again = fingerprintForDescription($path);

    @unlink($path);

    expect($second)->not->toBe($first)
        ->and($again)->toBe($first);
});

it('carries the contents BESIDE the path, not instead of it', function (): void {
    // The path has to survive: it is what the machine-dependent-path check reads, and a description
    // moved to another file is a config change in its own right. Over-keying costs a cache miss;
    // dropping the path would cost a diagnostic.
    $path = base_path('docuccino-fingerprint-raw.md');
    file_put_contents($path, "Prose.\n");

    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $raw['info'] = ['title' => 'Fingerprint', 'version' => '1.0.0', 'description' => ['file' => $path]];
    $config = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');

    @unlink($path);

    expect($config->raw['info']['description'])
        ->toBe(['file' => 'docuccino-fingerprint-raw.md', 'contents' => 'Prose.'])
        // …while what the document EMITS is the prose alone.
        ->and($config->info['description'])->toBe('Prose.');
});

it('reads the same prose from a CRLF file as from an LF one', function (): void {
    // A checkout's line endings are not a code change, so they must not reach the document.
    $path = base_path('docuccino-fingerprint-crlf.md');

    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $raw['info'] = ['title' => 'Fingerprint', 'version' => '1.0.0', 'description' => ['file' => $path]];

    file_put_contents($path, "First line.\r\n\r\nSecond line.\r\n");
    $crlf = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');

    file_put_contents($path, "First line.\n\nSecond line.\n");
    $lf = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');

    @unlink($path);

    expect($crlf->info['description'])->toBe("First line.\n\nSecond line.")
        ->and($crlf->info['description'])->toBe($lf->info['description'])
        ->and($crlf->hash())->toBe($lf->hash());
});
