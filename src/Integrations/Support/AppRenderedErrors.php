<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteNotes;

/**
 * The exceptions this route's build watched the APPLICATION's own handler render — a renderer that was
 * found, analysed, and answers with a response of its own instead of handing the throwable back to the
 * framework — and could not publish what it read. Written by the inferred-handler tier where it
 * DECLINES, which is where the readers below are reached: the chain stops at the first tier to answer,
 * so an exception this one answered for is never put to another, and a note recorded there would say
 * something nothing can hear.
 *
 * The tiers behind it read it to know when to stop talking. A framework-default body is a claim about
 * what the FRAMEWORK renders, and an application that demonstrably renders the exception itself has
 * already refuted it — so those tiers keep the status they classify and leave the body unsaid, rather
 * than asserting a shape and a media type the server does not send over the top of code that says
 * otherwise. Silence there is not a gap the next tier should fill either, which is why the tier that
 * withholds the body still answers and ends the chain.
 *
 * Only a renderer that RETURNED something counts. A `return null`/void arm is the renderer delegating to
 * the framework, and an analysis that recovered no return at all refutes nothing — in both, the framework
 * default is still the best answer anyone has, and an application with no handler never reaches here at
 * all. That is the whole gate: it keys on a renderer that says otherwise, never on a fold that failed.
 *
 * A {@see RouteContext::notes()} channel rather than a call between tiers, since the two live in separate
 * integrations and a note is the channel a per-route fact already travels on. It carries no collector —
 * nothing reports it — and it is read back inside the same build, before the chain reaches the tiers that
 * ask.
 */
final class AppRenderedErrors
{
    /** The {@see RouteNotes} channel; keyed by exception, valued by renderer. */
    public const CHANNEL = 'inferred-handler.app-rendered';

    public static function record(RouteContext $context, string $exceptionFqcn, string $renderer): void
    {
        $context->notes()->record(self::CHANNEL, $exceptionFqcn, $renderer);
    }

    /** Whether the application's own handler renders this exact exception type on this route. */
    public static function includes(RouteContext $context, string $exceptionFqcn): bool
    {
        return ($context->notes()->all()[self::CHANNEL][$exceptionFqcn] ?? []) !== [];
    }
}
