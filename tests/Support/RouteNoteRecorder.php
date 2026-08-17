<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;

/**
 * Records one chosen note on every route it sees, so a test can put an exact label into a document-level
 * summary without having to provoke whatever discovers one for real. It writes through the extension-author
 * surface — `RouteContext::notes()` — and therefore travels the road the product travels: onto the route's
 * fragment, out again into its collector on a warm hit as on a cold build.
 *
 * Seeding the collector itself is not the same test and no longer works: the pipeline empties every
 * collector before a document's first route, so that one document never reports another's findings.
 */
final class RouteNoteRecorder implements OperationExtension
{
    public function __construct(
        private readonly string $channel,
        private readonly string $key,
        private readonly string $value,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Errors;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $context->notes()->record($this->channel, $this->key, $this->value);
    }
}
