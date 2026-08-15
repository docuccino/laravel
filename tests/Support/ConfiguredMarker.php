<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;

/**
 * A {@see DocumentTransformer} whose behaviour comes from its INSTANCE, the way an extension registered
 * as `new MyExtension(mode: 'a')` does — so the fragment cache has to key on the configuration and not
 * just on the class name.
 */
final class ConfiguredMarker implements DocumentTransformer
{
    public function __construct(private readonly string $title) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        $info = $document->get('info');
        $info = is_array($info) ? $info : [];
        $info['title'] = $this->title;
        $document->set('info', $info);
    }
}
