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
 * Infers the success response(s) from the action's return paths (design §5): each return type is
 * unwrapped to a `(status, payload)` pair and grouped by status, so return paths with different
 * statuses become different responses. A `JsonResponse<TPayload, TStatus>` contributes its payload
 * shape (never a generic `{type: object}`) under the folded status — an `int` literal second type
 * arg, else the default 200; `noContent()` arrives as `JsonResponse<void, 204>`; bare `void`/`never`
 * contributes nothing.
 *
 * Being a built-in, it imports no integration: the three integration-aware decisions arrive through
 * gated context chains ({@see ResponseAnalysisTarget} redirects the analysed method,
 * {@see ResponseStatusResolver} re-homes a bare Data return's status,
 * {@see PayloadMediaTypeResolver} classifies the media type), so a disabled integration cannot shape
 * the success response. A redirect carries its own provenance producer.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class InferredResponsesExtension implements OperationExtension
{
    /** Matched by string so this extension carries no Illuminate import. */
    private const JSON_RESPONSE = 'Illuminate\\Http\\JsonResponse';

    private const DEFAULT_STATUS = '200';

    /**
     * RFC reason phrases for the statuses this extension emits; unlisted falls back to `OK`. 422 is
     * here because a `calculateResponseStatus()` override can re-home a body outside 2xx.
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
            // PHP coerced the '200' key to int; the draft API and reason table want the string back.
            $this->emit($operation, $context, (string) $status, $bucket['payloads'], $bucket['location'], $producer);
        }
    }

    /**
     * Place a return into `(status, payload)` bucket(s) — normally just the unwrapped pair. A bare
     * Data return can override `calculateResponseStatus()` and re-home its body off 200, possibly to
     * several statuses (a conditional whose arms all fold). For a union of Data classes each member
     * re-homes independently; members with no override, and non-class members, stay at 200.
     *
     * @return list<array{0: string, 1: ?DType}>
     */
    private function placeReturn(string $status, ?DType $payload, DType $returnType, RouteContext $context): array
    {
        // Only a bare Data return re-homes: default status, and the payload IS the whole return type
        // (a JsonResponse-wrapped payload already has its own folded status).
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
     * The same body under each resolved status, or under 200 when no override folded.
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
     * The analysis whose return sites define the success body, plus the producer to attribute it to.
     * Usually the action's own analysis with a null producer (plain inference); a
     * {@see ResponseAnalysisTarget} redirect swaps in another method's analysis and its producer, and
     * records that method's dependency files so editing it invalidates the cached fragment.
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
     * Unwrap a return type to `(status, payloadType, isEmptyBody)`. A `JsonResponse<payload, status>`
     * yields the payload under its folded status (void payload = empty body); anything else yields
     * itself under 200.
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

    /** A constant `int` literal status arg folds; anything dynamic falls back to 200. */
    private function foldStatus(?DType $statusArg): string
    {
        if ($statusArg instanceof LiteralT && is_int($statusArg->value)) {
            return (string) $statusArg->value;
        }

        return self::DEFAULT_STATUS;
    }

    /**
     * One response: the unioned payload schema under its resolved media type, or an empty body when
     * there's no payload (`noContent()`).
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

        if ($payloads === [] || $response->isBodyless()) {
            return;
        }

        $type = count($payloads) === 1 ? $payloads[0] : UnionT::of($this->dedupe($payloads));
        $result = $context->converter()->toSchema($type);

        // Anchor the body to the first contributing return path (design §4); no usable location means
        // a sourceless contribution rather than a churny one.
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
     * The media type from the gated {@see PayloadMediaTypeResolver} chain — e.g. JSON:API resources
     * serialise as `application/vnd.api+json`. Every payload must agree; a mixed union falls back to
     * `application/json`.
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
