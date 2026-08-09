<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Contracts\PayloadMediaTypeResolver;
use Docuccino\Core\Extensions\Contracts\ResponseAnalysisTarget;
use Docuccino\Core\Extensions\Contracts\ResponseStatusResolver;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Patch\Contribution;

/**
 * Infers the success response(s) from the action's return paths (design §5). Every return type is
 * unwrapped to a `(status, payload)` pair and grouped by HTTP status, so distinct return paths that
 * carry distinct statuses become distinct responses:
 *
 * - A `JsonResponse<TPayload, TStatus>` (recovered by the bundled PHPStan extension for
 *   `response()->json($x, 201)`) contributes its PAYLOAD shape under the folded status — the whole
 *   `JsonResponse` object is never rendered as a generic `{type: object}`.
 * - `noContent()` surfaces as `JsonResponse<void, 204>`: an empty response body under status 204.
 * - Any other return type keeps its default `200 application/json` mapping.
 *
 * The folded status is read from the second type arg when it is a constant `int` literal; a dynamic
 * status (non-literal) falls back to the default `200`. Bare `void`/`never` returns (no
 * `JsonResponse` wrapper) contribute nothing.
 *
 * This is a built-in adapter extension, so it reaches into NO integration: the three integration-aware
 * decisions all arrive through gated context chains (design §6 — an integration contributes only when
 * installed AND enabled). A {@see ResponseAnalysisTarget} may
 * redirect the analysed method (laravel-actions `jsonResponse()`), a
 * {@see ResponseStatusResolver} may re-home a bare Data return's
 * status (spatie `calculateResponseStatus()`), and a
 * {@see PayloadMediaTypeResolver} may classify the media type
 * (JSON:API resources). A disabled integration contributes none of these, so it cannot shape the
 * success response; a redirect carries the honest provenance producer.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class InferredResponsesExtension implements OperationExtension
{
    /** The Illuminate JSON response FQCN, matched by string so this extension carries no Illuminate/integration import. */
    private const JSON_RESPONSE = 'Illuminate\\Http\\JsonResponse';

    private const DEFAULT_STATUS = '200';

    /**
     * Canonical RFC reason phrases for the statuses this extension emits. Beyond the 2xx range a
     * `calculateResponseStatus()` override can re-home a Responsable body to a 4xx (e.g. challenge
     * DTOs that return 422), so those phrases are covered too; an unlisted status falls back to
     * `OK`.
     *
     * @var array<int, string>
     */
    private const REASONS = [
        '200' => 'OK',
        '201' => 'Created',
        '202' => 'Accepted',
        '203' => 'Non-Authoritative Information',
        '204' => 'No Content',
        '205' => 'Reset Content',
        '206' => 'Partial Content',
        '422' => 'Unprocessable Entity',
    ];

    public function phase(): OperationPhase
    {
        return OperationPhase::Responses;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        [$analysis, $producer] = $this->responseAnalysis($context);

        /** @var array<string, array{payloads: list<DType>, location: ?SourceLocation, empty: bool}> $byStatus */
        $byStatus = [];

        foreach ($analysis->returns as $return) {
            [$status, $payload, $empty] = $this->unwrap($return->type);

            // A bare void/never return (no JsonResponse wrapper) documents nothing.
            if ($payload === null && ! $empty) {
                continue;
            }

            foreach ($this->placeReturn($status, $payload, $return->type, $context) as [$placedStatus, $placedPayload]) {
                $bucket = $byStatus[$placedStatus] ??= ['payloads' => [], 'location' => null, 'empty' => false];
                $bucket['location'] ??= $return->location;
                if ($placedPayload !== null) {
                    $bucket['payloads'][] = $placedPayload;
                }
                $bucket['empty'] = $bucket['empty'] || ($placedPayload === null && $empty);
                $byStatus[$placedStatus] = $bucket;
            }
        }

        if ($byStatus === []) {
            return;
        }

        // Deterministic response order regardless of return-path scheduling.
        ksort($byStatus);

        foreach ($byStatus as $status => $bucket) {
            // A numeric-string status key ('200') is coerced to int by PHP array semantics; restore
            // the string the draft API and reason table expect.
            $this->emit($operation, $context, (string) $status, $bucket['payloads'], $bucket['location'], $producer);
        }
    }

    /**
     * Place a return into `(status, payload)` bucket(s). Normally one — the unwrapped pair. But a bare
     * Data return (Responsable, still at the default 200, the whole return type IS the payload) may
     * override `calculateResponseStatus()` to 201/202/… (or several statuses for a conditional whose
     * arms all fold): each folded status re-homes the body off 200. A UNION of Data classes returned
     * directly — e.g. `MfaChallengeData|EmailVerificationChallengeData|…` from one action — re-homes
     * EACH member to its own status(es); a member with no override, and any non-class member, stays at
     * 200. Overrides arrive through the gated {@see ResponseStatusResolver} chain, never a direct import.
     *
     * @return list<array{0: string, 1: ?DType}>
     */
    private function placeReturn(string $status, ?DType $payload, DType $returnType, RouteContext $context): array
    {
        // Only a bare Data return is re-homed: still the default 200, and the payload IS the whole
        // return type (a JsonResponse-wrapped payload keeps its own folded status — $returnType !== payload).
        if ($status !== self::DEFAULT_STATUS || $payload === null || $returnType !== $payload) {
            return [[$status, $payload]];
        }

        if ($payload instanceof ClassT) {
            return $this->placeByStatuses($payload, $context->resolveResponseStatuses($payload->fqcn));
        }

        if ($payload instanceof UnionT) {
            $out = [];
            foreach ($payload->members as $member) {
                $out = $member instanceof ClassT
                    ? [...$out, ...$this->placeByStatuses($member, $context->resolveResponseStatuses($member->fqcn))]
                    : [...$out, [self::DEFAULT_STATUS, $member]];
            }

            return $out;
        }

        return [[$status, $payload]];
    }

    /**
     * Place one payload under each resolved status (the same body under every status), or under the
     * default 200 when no override folded.
     *
     * @param  list<int>  $statuses
     * @return list<array{0: string, 1: ?DType}>
     */
    private function placeByStatuses(DType $payload, array $statuses): array
    {
        if ($statuses === []) {
            return [[self::DEFAULT_STATUS, $payload]];
        }

        return array_map(static fn (int $s): array => [(string) $s, $payload], $statuses);
    }

    /**
     * The analysis whose return sites define the success body, paired with the provenance producer to
     * attribute it to. Normally the dispatched action's own ({@see RouteContext::analysis()}) with a
     * null producer (→ plain inference); when a gated {@see ResponseAnalysisTarget}
     * redirects (a laravel-actions `jsonResponse()`), it is that method's analysis and the redirect's
     * producer (e.g. `integration:laravel-actions`), so the body is attributed honestly. Analysing the
     * redirected method directly keeps a single source for the 200 schema; its dependency files are
     * recorded so editing it still invalidates the cached fragment.
     *
     * @return array{0: ActionAnalysis, 1: ?string}
     */
    private function responseAnalysis(RouteContext $context): array
    {
        $redirect = $context->responseAnalysisRedirect();
        if ($redirect === null) {
            return [$context->analysis(), null];
        }

        $analysis = $context->engine->analyzeAction($redirect->ref);
        $context->recordDependencyFiles($analysis->dependencyFiles);

        return [$analysis, $redirect->producer];
    }

    /**
     * Unwrap a return type into its `(status, payloadType, isEmptyBody)` triple. A
     * `JsonResponse<payload, status>` yields the payload under its folded status (void payload =
     * empty body); anything else yields itself under `200`.
     *
     * @return array{0: string, 1: ?DType, 2: bool}
     */
    private function unwrap(DType $type): array
    {
        if ($type instanceof VoidT || $type instanceof NeverT) {
            return [self::DEFAULT_STATUS, null, false];
        }

        if ($type instanceof ClassT && $type->fqcn === self::JSON_RESPONSE) {
            $status = $this->foldStatus($type->typeArgs[1] ?? null);
            $payload = $type->typeArgs[0] ?? null;

            if ($payload === null || $payload instanceof VoidT || $payload instanceof NeverT) {
                return [$status, null, true];
            }

            return [$status, $payload, false];
        }

        return [self::DEFAULT_STATUS, $type, false];
    }

    /** The folded status from a `JsonResponse` status type arg — a constant `int` literal, else 200. */
    private function foldStatus(?DType $statusArg): string
    {
        if ($statusArg instanceof LiteralT && is_int($statusArg->value)) {
            return (string) $statusArg->value;
        }

        return self::DEFAULT_STATUS;
    }

    /**
     * Emit one response: the unioned payload schema under its resolved media type, or an empty-bodied
     * response when there is no payload (e.g. `noContent()`).
     *
     * @param  list<DType>  $payloads
     */
    private function emit(
        OperationDraft $operation,
        RouteContext $context,
        string $status,
        array $payloads,
        ?SourceLocation $location,
        ?string $producer,
    ): void {
        $response = $operation->response($status);
        $response->setDescription(self::REASONS[$status] ?? 'OK', Contribution::fallback());

        if ($payloads === []) {
            return;
        }

        $type = count($payloads) === 1 ? $payloads[0] : UnionT::of($this->dedupe($payloads));
        $result = $context->converter()->toSchema($type);

        // Anchor the inferred body to the first contributing return path (design §4); an engine that
        // reports no usable location yields a sourceless contribution rather than a churny one. When a
        // redirect fired (laravel-actions jsonResponse) the body is attributed to that integration's
        // producer, not plain inference.
        $source = $location !== null ? $context->sourceAt($location) : null;
        $contribution = $producer !== null
            ? Contribution::forProducer($producer, $source, $result->confidence)
            : Contribution::inference($source, $result->confidence);

        $mediaType = $this->mediaType($context, $payloads);
        foreach ($result->schema as $keyword => $value) {
            $response->content($mediaType)->set($keyword, $value, $contribution);
        }
    }

    /**
     * The response media type from the gated {@see PayloadMediaTypeResolver}
     * chain: JSON:API resources (either enabled family, or a collection of them) serialise as
     * `application/vnd.api+json`; everything else stays `application/json`. Every payload must resolve
     * to the same non-JSON media type — a mixed union falls back to `application/json`.
     *
     * @param  list<DType>  $payloads
     */
    private function mediaType(RouteContext $context, array $payloads): string
    {
        $resolved = null;
        foreach ($payloads as $payload) {
            $mediaType = $context->payloadMediaType($payload);
            if ($mediaType === 'application/json') {
                return 'application/json';
            }
            $resolved = $mediaType;
        }

        return $resolved ?? 'application/json';
    }

    /**
     * @param  list<DType>  $types
     * @return list<DType>
     */
    private function dedupe(array $types): array
    {
        $byKey = [];
        foreach ($types as $type) {
            $byKey[$type->canonicalKey()] = $type;
        }

        return array_values($byKey);
    }
}
