<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
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
use Docuccino\Laravel\Support\FrameworkClasses;

/**
 * Infers the success response(s) from the action's return paths (design §5): each return type is
 * unwrapped to a `(status, payload)` pair and grouped by status, so return paths with different
 * statuses become different responses. A `JsonResponse<TPayload, TStatus>` contributes its payload
 * shape (never a generic `{type: object}`) under the folded status — an `int` literal second type
 * arg, else the default 200; `noContent()` arrives as `JsonResponse<void, 204>`; bare `void`/`never`
 * contributes nothing; and an unparameterised framework response gets only what the class itself proves
 * ({@see frameworkResponse()}).
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
    private const DEFAULT_STATUS = '200';

    /**
     * The OAS range key a redirect documents under: `RedirectResponse` takes any 3xx and the return site
     * names none of them, so the range is what the code proves and 302 would be a guess.
     */
    private const REDIRECT_STATUS = '3XX';

    /** Every redirect sets `Location`; that much the response class itself proves. */
    private const LOCATION_HEADER = [
        'Location' => [
            'description' => 'The URL to follow.',
            'schema' => ['type' => 'string', 'format' => 'uri-reference'],
        ],
    ];

    /**
     * RFC reason phrases for the statuses this extension emits; unlisted falls back to `OK`. 422 is here
     * because a `calculateResponseStatus()` override can re-home a body outside 2xx, and the `3XX` range
     * key gets a plain word since no RFC names one.
     *
     * @var array<int|string, string>
     */
    private const REASONS = [
        '200' => 'OK',
        '201' => 'Created',
        '202' => 'Accepted',
        '203' => 'Non-Authoritative Information',
        '204' => 'No Content',
        '205' => 'Reset Content',
        '206' => 'Partial Content',
        '3XX' => 'Redirect',
        '422' => 'Unprocessable Entity',
    ];

    public function phase(): OperationPhase
    {
        return OperationPhase::Responses;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        [$analysis, $producer] = $this->responseAnalysis($context);

        /** @var array<string, array{payloads: list<DType>, location: ?SourceLocation, empty: bool, headers: ?array<string, mixed>}> $byStatus */
        $byStatus = [];

        /** @var array<string, true> $unrecovered */
        $unrecovered = [];

        foreach ($analysis->returns as $return) {
            $bare = $this->unrecoveredResponse($return->type);
            if ($bare !== null) {
                $unrecovered[$bare] = true;
            }

            [$status, $payload, $empty, $headers] = $this->unwrap($return->type);

            // A bare void/never return (no JsonResponse wrapper) documents nothing.
            if ($payload === null && ! $empty) {
                continue;
            }

            foreach ($this->placeReturn($status, $payload, $return->type, $context) as [$placedStatus, $placedPayload]) {
                $bucket = $byStatus[$placedStatus] ??= ['payloads' => [], 'location' => null, 'empty' => false, 'headers' => null];
                $bucket['location'] ??= $return->location;
                if ($placedPayload !== null) {
                    $bucket['payloads'][] = $placedPayload;
                }
                $bucket['empty'] = $bucket['empty'] || ($placedPayload === null && $empty);
                $bucket['headers'] ??= $headers;
                $byStatus[$placedStatus] = $bucket;
            }
        }

        $this->reportUnrecovered($context, array_keys($unrecovered));

        if ($byStatus === []) {
            return;
        }

        // Deterministic response order regardless of return-path scheduling.
        ksort($byStatus);

        foreach ($byStatus as $status => $bucket) {
            // PHP coerced the '200' key to int; the draft API and reason table want the string back.
            $this->emit($operation, $context, (string) $status, $bucket['payloads'], $bucket['location'], $producer, $bucket['headers']);
        }

        if (isset($byStatus['3XX'])) {
            $this->reportUnpinnedRedirect($context);
        }
    }

    /**
     * The return site redirects but doesn't say to what code, so the response lands on the `3XX` range.
     * Pinning it is advice for the API's author, hence a diagnostic rather than a description consumers read.
     */
    private function reportUnpinnedRedirect(RouteContext $context): void
    {
        $context->components->addDiagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'inferred-response.unpinned-redirect',
            message: sprintf(
                '%s returns a redirect whose exact 3xx status is not stated at the return site, so it is documented as the 3XX range.',
                $context->actionRef->symbol(),
            ),
            routeSignature: $context->route->signature(),
            help: 'Pin it with #[Response(302)] (or the code this endpoint always answers with) when the redirect is not conditional.',
        ));
    }

    /**
     * One diagnostic per framework response class the analyser handed back bare. The degradation is
     * unavoidable, but silence isn't — a body-less response otherwise reads as a deliberate empty one.
     *
     * @param  list<string>  $fqcns
     */
    private function reportUnrecovered(RouteContext $context, array $fqcns): void
    {
        foreach ($fqcns as $fqcn) {
            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'inferred-response.payload-unrecoverable',
                message: sprintf(
                    '%s returns a bare %s, so nothing names the response body and its shape could not be recovered.',
                    $context->actionRef->symbol(),
                    $fqcn,
                ),
                routeSignature: $context->route->signature(),
                help: 'Build the payload where the analyser can see it (return response()->json($payload) from the action itself rather than handing back a collaborator\'s response), or declare the body with #[Response(type: YourPayload::class)].',
            ));
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
     * Unwrap a return type to `(status, payloadType, isEmptyBody, headers)`. A
     * `JsonResponse<payload, status>` yields the payload under its folded status (void payload =
     * empty body); an unparameterised framework response goes to {@see frameworkResponse()};
     * anything else yields itself under 200.
     *
     * @return array{0: string, 1: ?DType, 2: bool, 3: ?array<string, mixed>}
     */
    private function unwrap(DType $type): array
    {
        if ($type instanceof VoidT || $type instanceof NeverT) {
            return [self::DEFAULT_STATUS, null, false, null];
        }

        if ($type instanceof ClassT && $type->fqcn === FrameworkClasses::JSON_RESPONSE && $type->typeArgs !== []) {
            $status = $this->foldStatus($type->typeArgs[1] ?? null);
            $payload = $type->typeArgs[0];

            if ($payload instanceof VoidT || $payload instanceof NeverT) {
                return [$status, null, true, null];
            }

            return [$status, $payload, false, null];
        }

        if ($type instanceof ClassT && FrameworkClasses::isResponse($type->fqcn)) {
            return $this->frameworkResponse($type);
        }

        return [self::DEFAULT_STATUS, $type, false, null];
    }

    /**
     * A framework response object handed back with no payload generic: transport, not a body, so only what
     * the class itself proves is documented. A redirect proves a 3xx plus `Location` and no body; a bare
     * `JsonResponse` proves a JSON body of an unrecovered shape, which converts to an open `{}` rather than
     * the no-body claim the class contradicts; anything else proves neither media type nor shape.
     *
     * @return array{0: string, 1: ?DType, 2: bool, 3: ?array<string, mixed>}
     */
    private function frameworkResponse(ClassT $type): array
    {
        if (FrameworkClasses::isRedirect($type->fqcn)) {
            return [self::REDIRECT_STATUS, null, true, self::LOCATION_HEADER];
        }

        if ($type->fqcn === FrameworkClasses::JSON_RESPONSE) {
            return [self::DEFAULT_STATUS, $type, false, null];
        }

        return [self::DEFAULT_STATUS, null, true, null];
    }

    /**
     * The FQCN of a framework response handed back with no payload generic — the case that costs the
     * document a body, so the one worth a diagnostic. A redirect isn't one: it proves all a redirect has.
     */
    private function unrecoveredResponse(DType $type): ?string
    {
        if (! $type instanceof ClassT || ! FrameworkClasses::isResponse($type->fqcn) || FrameworkClasses::isRedirect($type->fqcn)) {
            return null;
        }

        return $type->fqcn === FrameworkClasses::JSON_RESPONSE && $type->typeArgs !== [] ? null : $type->fqcn;
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
     * there's no payload (`noContent()`). Headers are the ones the return type itself proves — a
     * redirect's `Location` — and are written as inference, not fallback, since the code stated them.
     *
     * @param  list<DType>  $payloads
     * @param  ?array<string, mixed>  $headers
     */
    private function emit(
        OperationDraft $operation,
        RouteContext $context,
        string $status,
        array $payloads,
        ?SourceLocation $location,
        ?string $producer,
        ?array $headers = null,
    ): void {
        $response = $operation->response($status);
        $response->setDescription(self::REASONS[$status] ?? 'OK', Contribution::fallback());

        if ($headers !== null) {
            $response->set('headers', $headers, Contribution::inference($context->actionSource()));
        }

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

        // Registered even when the payload converted to an open `{}` — an absent content entry would read
        // as "no body at all", which is the one thing inference has ruled out.
        $mediaType = $this->mediaType($context, $payloads);
        $content = $response->content($mediaType);
        foreach ($result->schema as $keyword => $value) {
            $content->set($keyword, $value, $contribution);
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
