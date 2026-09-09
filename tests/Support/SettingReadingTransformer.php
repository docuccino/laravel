<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Laravel\Config\BuildConfig;

/**
 * An extension that reads a setting through the typed reader while the document is being built, which
 * is when an extension's hooks run.
 *
 * It exists to stand for the population that can refuse a setting DURING a build: the build's own
 * readers all ask before generating, so nothing else in-tree makes a refusal the report could lose.
 */
final class SettingReadingTransformer implements DocumentTransformer
{
    /** The setting it reads, written here so a test can name the refusal it expects. */
    public const string SETTING = 'documents.default.versioning';

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        app(BuildConfig::class)->values()->string(self::SETTING, 'none');
    }
}
