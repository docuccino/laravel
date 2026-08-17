<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\InferredHandler;

/**
 * The one route the callback-diagnostics golden needs: a deferral is discovered by a route, so its label
 * reaches the summary as a note on that route's fragment and something has to carry it.
 *
 * It lives here, beside {@see PortableCallbackLabels} and for the same reason: the golden pins whatever
 * this operation emits, so the action carries no attribute, no docblock prose and no recoverable return —
 * nothing whose provenance would name a line, and therefore nothing an edit elsewhere can move.
 */
final class DeferralCarrierController
{
    public function index(): void {}
}
