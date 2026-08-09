<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;

/**
 * A trivial {@see DocumentTransformer} used by the late-binding trap test: registering it AFTER
 * the app has booted must still change the output, proving the registry resolves at build time.
 */
final class LateBoundMarker implements DocumentTransformer
{
    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        $info = $document->get('info');
        $info = is_array($info) ? $info : [];
        $info['title'] = 'LATE-BOUND';
        $document->set('info', $info);
    }
}
