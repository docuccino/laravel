<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ErrorResponseFinalizer;
use Docuccino\Core\Extensions\Contracts\Finalization;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;

/**
 * Documents what `$exceptions->respond(…)` makes of every rendered error, reading the callback as a
 * post-processor: every return reachable for the throw counts, and one handing the rendered response back
 * unchanged is an answer rather than an early-out. How each return is read is design §6 (uir-and-extensions).
 */
final class RespondCallbackFinalizer implements ErrorResponseFinalizer
{
    public function __construct(
        private readonly HandlerReflector $reflector,
        private readonly ExceptionRenderers $renderers,
    ) {}

    public function producer(): string
    {
        return InferredHandlerExceptionToResponse::PRODUCER;
    }

    public function finalization(ThrownException $exception, ResponseDraft $rendered, RouteContext $context): Finalization
    {
        $callback = $this->reflector->respondCallback();
        if ($callback === null) {
            // Registered but nowhere to read: every error this route renders may be rewritten, unread.
            $unlocated = $this->reflector->respondUnlocated();
            if ($unlocated !== null) {
                HandlerDeferralLog::record($context, $unlocated, $exception->exceptionFqcn);
            }

            return Finalization::Keeps;
        }

        $plan = $this->plan($callback, $exception, $rendered, $context);

        // Editing the callback, or a helper it builds its response through, must rebuild this route.
        $context->recordDependencyFiles($plan['analysis']->dependencyFiles);

        // A replacement withdraws the notes rendering left, and this one with them — so it is written with
        // the replacement instead ({@see responses()}).
        if ($plan['unread'] && $plan['finalization'] !== Finalization::Replaces) {
            HandlerDeferralLog::record($context, $plan['target'], $exception->exceptionFqcn);
        }

        return $plan['finalization'];
    }

    public function responses(
        ThrownException $exception,
        ResponseDraft $rendered,
        RouteContext $context,
        ComponentRegistry $components,
    ): array {
        $callback = $this->reflector->respondCallback();
        if ($callback === null) {
            return [];
        }

        $plan = $this->plan($callback, $exception, $rendered, $context);
        $contribution = Contribution::integration('inferred-handler');
        HandlerResponseBuilder::reportIllegalNames(new ActionAnalysis(returns: $plan['rewrites']), $context, $components);

        if ($plan['unread'] && $plan['finalization'] === Finalization::Replaces) {
            HandlerDeferralLog::record($context, $plan['target'], $exception->exceptionFqcn);
        }

        if ($plan['rewrites'] === []) {
            return [$this->unsaid($rendered->status)];
        }

        $drafts = [];
        foreach ($plan['rewrites'] as $site) {
            $drafts[] = HandlerResponseBuilder::build(new ActionAnalysis(returns: [$site]), $context, $contribution, $plan['exception'], $plan['target'])
                ?? $this->unreadJson($site, $plan['exception'], $plan['target'], $context);
        }

        return $drafts;
    }

    /** A response at a status whose body nothing read. */
    private function unsaid(string $status): ResponseDraft
    {
        $draft = new ResponseDraft($status);
        $draft->setDescription(FrameworkExceptionTable::reason($status), Contribution::integration('inferred-handler'));

        return $draft;
    }

    /**
     * A `JsonResponse` with nothing about it read: it is JSON, at the status it is filed under, and its
     * shape is unknown — an empty schema, and the author told.
     */
    private function unreadJson(ReturnSite $site, ThrownException $exception, string $target, RouteContext $context): ResponseDraft
    {
        $status = HandlerResponseBuilder::statusOf($site, $exception) ?? FrameworkExceptionTable::classification($exception->exceptionFqcn);

        $draft = $this->unsaid($status);
        $draft->content('application/json');
        HandlerDeferralLog::record($context, $target, $exception->exceptionFqcn);

        return $draft;
    }

    /**
     * The one reading both questions are answered from, so they cannot disagree.
     *
     * @return array{finalization: Finalization, rewrites: list<ReturnSite>, unread: bool, analysis: ActionAnalysis, exception: ThrownException, target: string}
     */
    private function plan(RespondCallback $callback, ThrownException $exception, ResponseDraft $rendered, RouteContext $context): array
    {
        $ref = $callback->ref($exception->exceptionFqcn);
        $analysis = $context->engine->analyzeCallable($ref);

        // A rewrite that does not state its own status sends the one it was handed. A stand-in status is
        // not a reading, so it is not handed on as one: the rewrite files where the rendered response did.
        $hint = RespondConditions::renderedStatus($rendered);
        $hinted = $exception->as($exception->exceptionFqcn, $hint);

        $plan = ['rewrites' => [], 'unread' => false, 'analysis' => $analysis, 'exception' => $hinted, 'target' => $ref->target()];

        if ($analysis->returns === []) {
            return ['finalization' => Finalization::Keeps, 'unread' => true] + $plan;
        }

        $sent = RenderedResponse::of($exception, $rendered, $this->renderers);

        $echoes = false;
        $rewrites = [];
        $statuses = [];
        foreach ($analysis->returns as $site) {
            if (! RespondConditions::reachable([...$site->conditions, ...$site->typeConditions], $callback, $context, $rendered, $sent)) {
                continue;
            }

            if ($callback->responseParameter !== null && $site->returnsParameter === $callback->responseParameter) {
                $echoes = true;

                continue;
            }

            $status = HandlerResponseBuilder::statusOf($site, $hinted);
            if ($status === null) {
                $plan['unread'] = true;

                continue;
            }

            $rewrites[] = $site;
            $statuses[$status] = true;
        }

        if ($rewrites === []) {
            // Nothing read. Where no reachable return hands the rendered response back either, whatever is
            // sent is not the body the tiers before this one published, so that body goes unsaid and the
            // status stays — the answer a tier gives for a renderer it watched and could not read.
            return ['finalization' => $plan['unread'] && ! $echoes ? Finalization::Replaces : Finalization::Keeps] + $plan;
        }

        $keepsRendered = $echoes || $plan['unread'];

        // One throw is one response. Rewrites that land at different statuses, or away from a rendered
        // response still sent beside them, are not one response this can publish — so the rendered one
        // stands and the author hears why.
        if (count($statuses) > 1 || ($keepsRendered && ! isset($statuses[$rendered->status]))) {
            return ['finalization' => Finalization::Keeps, 'unread' => true] + $plan;
        }

        return ['finalization' => $keepsRendered ? Finalization::Extends : Finalization::Replaces, 'rewrites' => $rewrites] + $plan;
    }
}
