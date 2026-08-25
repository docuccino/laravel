<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\Response;
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
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Support\BinaryRepresentation;
use Docuccino\Laravel\Support\FrameworkClasses;
use Docuccino\Laravel\Support\IgnoredResponses;

/**
 * Infers the success response(s) from the action's return paths (design §5): each return type is
 * unwrapped to a `(status, payload)` pair and grouped by status, so return paths with different
 * statuses become different responses. A `JsonResponse<TPayload, TStatus>` contributes its payload
 * shape (never a generic `{type: object}`) under the folded status — an `int` literal second type
 * arg, else the default 200; `noContent()` arrives as `JsonResponse<void, 204>`; bare `void`/`never`
 * contributes nothing; and an unparameterised framework response gets only what the class itself
 * proves, plus — for a file or streamed body — what the call that built it proves
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
     * names none of them, so the range is what the code proves and 302 would be a guess. Whether it is
     * still there once every layer has spoken belongs to the document lint of the same name, not here: an
     * attribute retires it ({@see OperationDraft::supersedeStatusRange()}), and a diagnostic raised from
     * this side would tell an author who pinned the code to pin it again.
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

        $fileCalls = $this->fileResponseCalls($analysis, $context);

        /** @var array<string, array{payloads: list<DType>, location: ?SourceLocation, empty: bool, headers: ?array<string, mixed>, bodies: array<string, array<string, mixed>>}> $byStatus */
        $byStatus = [];

        /** @var array<string, list<string>> $unrecovered */
        $unrecovered = [];

        foreach ($analysis->returns as $return) {
            [$status, $payload, $empty, $headers, $bodies] = $this->unwrap($return->type, $fileCalls);

            // Every status the class landed on, not just the first: two return paths of one bare
            // JsonResponse are two responses the document cannot describe, and a declaration at one of
            // them settles that one.
            $bare = $this->unrecoveredResponse($return->type, $bodies);
            if ($bare !== null && ! in_array($status, $unrecovered[$bare] ?? [], true)) {
                $unrecovered[$bare][] = $status;
            }

            // A bare void/never return (no JsonResponse wrapper) documents nothing.
            if ($payload === null && ! $empty && $bodies === []) {
                continue;
            }

            foreach ($this->placeReturn($status, $payload, $return->type, $context) as [$placedStatus, $placedPayload]) {
                $bucket = $byStatus[$placedStatus] ??= ['payloads' => [], 'location' => null, 'empty' => false, 'headers' => null, 'bodies' => []];
                $bucket['location'] ??= $return->location;
                if ($placedPayload !== null) {
                    $bucket['payloads'][] = $placedPayload;
                }
                $bucket['empty'] = $bucket['empty'] || ($placedPayload === null && $empty);
                $bucket['headers'] ??= $headers;
                $bucket['bodies'] = [...$bucket['bodies'], ...$bodies];
                $byStatus[$placedStatus] = $bucket;
            }
        }

        $this->reportUnrecovered($context, $unrecovered);

        if ($byStatus === []) {
            return;
        }

        // Deterministic response order regardless of return-path scheduling.
        ksort($byStatus);

        foreach ($byStatus as $status => $bucket) {
            // PHP coerced the '200' key to int; the draft API and reason table want the string back.
            $status = (string) $status;

            // Asked here rather than at the top, because the status a return path lands on is only known
            // once the resolver chain has placed it — and asked BEFORE emit(), because emit() converts the
            // payload, which is where a body hoists ({@see IgnoredResponses}).
            if (IgnoredResponses::drops($context, $status)) {
                continue;
            }

            $this->emit($operation, $context, $status, $bucket['payloads'], $bucket['location'], $producer, $bucket['headers'], $bucket['bodies']);
        }
    }

    /**
     * One diagnostic per framework response class whose body the document still cannot describe. The
     * degradation is unavoidable, but silence isn't — a body-less response otherwise reads as a
     * deliberate empty one. A streamed body loses its media type rather than its shape, so it gets the
     * advice that actually fixes it.
     *
     * Unlike the redirect range, this is not a question the finished document can answer: a framework
     * response the analyzer got nothing from is documented as no body at all, which reads exactly like a
     * deliberately empty one. So the one extra fact needed — did the author name the body themselves —
     * is read here ({@see named()}), rather than the notice firing at somebody who did.
     *
     * @param  array<string, list<string>>  $fqcns  the unrecovered response class → every status it landed on
     */
    private function reportUnrecovered(RouteContext $context, array $fqcns): void
    {
        foreach ($fqcns as $fqcn => $statuses) {
            // The streamed JSON response is the exception: it names its own media type, so what it is
            // missing is the SHAPE, and the payload advice is the one that fixes it.
            $streamed = (FrameworkClasses::isStreamed($fqcn) || FrameworkClasses::isBinaryFile($fqcn))
                && ! FrameworkClasses::isStreamedJson($fqcn);

            if ($this->named($context, $statuses, $streamed)) {
                continue;
            }

            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'inferred-response.payload-unrecoverable',
                message: sprintf(
                    $streamed
                        ? '%s returns a bare %s, and nothing at the call site names the media type it streams, so the body is documented as any media type at all.'
                        : '%s returns a bare %s, so nothing names the response body and its shape could not be recovered.',
                    $context->actionRef->symbol(),
                    $fqcn,
                ),
                routeSignature: $context->route->signature(),
                help: $streamed
                    ? 'Name the type where the response is built (response()->stream($callback, 200, [\'Content-Type\' => \'text/csv\'])), or declare the body and its media type together with #[Response(type: YourPayload::class, mediaType: \'text/csv\')] — mediaType alone documents nothing to put under it.'
                    : 'Build the payload where the analyzer can see it (return response()->json($payload) from the action itself rather than handing back a collaborator\'s response), or declare the body with #[Response(type: YourPayload::class)].',
            ));
        }
    }

    /**
     * Whether the author has already said what these statuses carry — ALL of them. A declaration settles
     * the status it names and no other, so a class that reached two statuses is only silenced by two.
     *
     * @param  list<string>  $statuses
     */
    private function named(RouteContext $context, array $statuses, bool $streamed): bool
    {
        foreach ($statuses as $status) {
            if (! $this->namedAt($context, $status, $streamed)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether one status is settled: a `#[Response]` naming a body type for it, or an `#[IgnoreResponse]`
     * saying the response is not real. Both run a layer above this one and settle exactly the fact the
     * diagnostic reports missing, so firing anyway would ask for a declaration that is already there. A
     * `#[Response]` with no `type:` is not one of them: it names a status and leaves the body as
     * unrecovered as it found it.
     *
     * A streamed body is missing its MEDIA TYPE rather than its shape, so there the declaration has to
     * name one as well — `mediaType:` left unwritten is the JSON default, which is not a statement about
     * what the stream sends, and the notice says exactly that.
     */
    private function namedAt(RouteContext $context, string $status, bool $streamed): bool
    {
        if (IgnoredResponses::drops($context, $status)) {
            return true;
        }

        foreach ($context->attributes->all(Response::class) as $declared) {
            if ($declared->type === null || (string) $declared->status !== $status) {
                continue;
            }

            if (! $streamed || $declared->mediaType !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The file/stream factory calls this action makes, or null when no return path is one of those
     * responses. Traced once per route and only when it can pay for itself: the return TYPE is the same
     * `BinaryFileResponse` for a download and for an inline file, so the call site is the only place the
     * difference — and the media type — is written down. Tracing through the context records the walk's
     * files, so a warm build reports what a cold one did.
     */
    private function fileResponseCalls(ActionAnalysis $analysis, RouteContext $context): ?FileResponseVisitor
    {
        foreach ($analysis->returns as $return) {
            $type = $return->type;
            if ($type instanceof ClassT && (FrameworkClasses::isBinaryFile($type->fqcn) || FrameworkClasses::isStreamed($type->fqcn))) {
                $context->trace($visitor = new FileResponseVisitor);

                return $visitor;
            }
        }

        return null;
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
     * Unwrap a return type to `(status, payloadType, isEmptyBody, headers, bodies)`. A
     * `JsonResponse<payload, status>` yields the payload under its folded status (void payload =
     * empty body); an unparameterised framework response goes to {@see frameworkResponse()};
     * anything else yields itself under 200. `bodies` are media-type-keyed schemas the response class
     * proves directly, rather than payload types the converter has to map.
     *
     * @return array{0: string, 1: ?DType, 2: bool, 3: ?array<string, mixed>, 4: array<string, array<string, mixed>>}
     */
    private function unwrap(DType $type, ?FileResponseVisitor $fileCalls): array
    {
        if ($type instanceof VoidT || $type instanceof NeverT) {
            return [self::DEFAULT_STATUS, null, false, null, []];
        }

        if ($type instanceof ClassT && $type->fqcn === FrameworkClasses::JSON_RESPONSE && $type->typeArgs !== []) {
            $status = $this->foldStatus($type->typeArgs[1] ?? null);
            $payload = $type->typeArgs[0];

            if ($payload instanceof VoidT || $payload instanceof NeverT) {
                return [$status, null, true, null, []];
            }

            return [$status, $payload, false, null, []];
        }

        if ($type instanceof ClassT && FrameworkClasses::isResponse($type->fqcn)) {
            return $this->frameworkResponse($type, $fileCalls);
        }

        return [self::DEFAULT_STATUS, $type, false, null, []];
    }

    /**
     * A framework response object handed back with no payload generic: transport, not a body, so only what
     * the class itself proves is documented. A redirect proves a 3xx plus `Location` and no body; a bare
     * `JsonResponse` proves a JSON body of an unrecovered shape, which converts to an open `{}` rather than
     * the no-body claim the class contradicts; a file or streamed response goes to {@see fileResponse()};
     * anything else proves neither media type nor shape.
     *
     * @return array{0: string, 1: ?DType, 2: bool, 3: ?array<string, mixed>, 4: array<string, array<string, mixed>>}
     */
    private function frameworkResponse(ClassT $type, ?FileResponseVisitor $fileCalls): array
    {
        if (FrameworkClasses::isRedirect($type->fqcn)) {
            return [self::REDIRECT_STATUS, null, true, self::LOCATION_HEADER, []];
        }

        if ($type->fqcn === FrameworkClasses::JSON_RESPONSE) {
            return [self::DEFAULT_STATUS, $type, false, null, []];
        }

        if (FrameworkClasses::isBinaryFile($type->fqcn) || FrameworkClasses::isStreamed($type->fqcn)) {
            return $this->fileResponse($type, $fileCalls);
        }

        return [self::DEFAULT_STATUS, null, true, null, []];
    }

    /**
     * A file, download or streamed response. The class proves a body exists and that it is received as
     * opaque bytes; which media type and whether it is offered as a download live at the CALL, so the
     * recovered calls answer where there are any and the class's own fallback answers where there are
     * none ({@see BinaryRepresentation}). Several calls at one status become one content entry each.
     *
     * @return array{0: string, 1: ?DType, 2: bool, 3: ?array<string, mixed>, 4: array<string, array<string, mixed>>}
     */
    private function fileResponse(ClassT $type, ?FileResponseVisitor $fileCalls): array
    {
        $calls = $fileCalls?->forClass($type->fqcn) ?? [];

        if ($calls === []) {
            return [self::DEFAULT_STATUS, null, false, null, $this->classBody($type->fqcn)];
        }

        $bodies = [];
        foreach ($calls as $call) {
            $bodies[$call->mediaType] = $call->schema;
        }

        return [self::DEFAULT_STATUS, null, false, $this->dispositionHeader($calls), $bodies];
    }

    /**
     * What the response CLASS alone proves, for a return whose building call was never reached. A streamed
     * JSON response fixes its own media type; a file response falls back to the octet-stream the server
     * itself sends; a callback-written stream names nothing at all.
     *
     * @return array<string, array<string, mixed>>
     */
    private function classBody(string $fqcn): array
    {
        if (FrameworkClasses::isStreamedJson($fqcn)) {
            return ['application/json' => []];
        }

        return FrameworkClasses::isBinaryFile($fqcn)
            ? [BinaryRepresentation::OCTET_STREAM => BinaryRepresentation::SCHEMA]
            : [BinaryRepresentation::ANY_MEDIA_TYPE => BinaryRepresentation::SCHEMA];
    }

    /**
     * The `Content-Disposition` the recovered calls prove, or null when they don't. Every call has to
     * agree: an action that both downloads and displays sets the header on one path and not the other, so
     * documenting either would be a claim about the wrong one. The example is only as specific as the
     * filename recovered — a header with no name behind it gets none.
     *
     * @param  list<FileResponseCall>  $calls
     * @return ?array<string, mixed>
     */
    private function dispositionHeader(array $calls): ?array
    {
        $disposition = $calls[0]->disposition;
        $filename = $calls[0]->filename;

        foreach ($calls as $call) {
            if ($call->disposition !== $disposition) {
                return null;
            }
            $filename = $call->filename === $filename ? $filename : null;
        }

        if ($disposition === null) {
            return null;
        }

        $header = [
            'description' => $disposition === FileResponseCall::ATTACHMENT
                ? 'Asks the client to save the body as a file rather than display it.'
                : 'Asks the client to display the body rather than save it.',
            'schema' => ['type' => 'string'],
        ];

        if ($filename !== null) {
            $header['example'] = sprintf('%s; filename="%s"', $disposition, $filename);
        }

        return ['Content-Disposition' => $header];
    }

    /**
     * The FQCN of a framework response whose body the document still cannot describe — the case that costs
     * a consumer something, so the one worth a diagnostic. A redirect isn't one: it proves all a redirect
     * has. Neither is a file download whose bytes are documented under a media type; what remains is a body
     * with no media type at all, or a JSON body of an unrecovered shape.
     *
     * @param  array<string, array<string, mixed>>  $bodies
     */
    private function unrecoveredResponse(DType $type, array $bodies): ?string
    {
        if (! $type instanceof ClassT || ! FrameworkClasses::isResponse($type->fqcn) || FrameworkClasses::isRedirect($type->fqcn)) {
            return null;
        }

        if ($type->fqcn === FrameworkClasses::JSON_RESPONSE) {
            // Parameterised is not the same as recovered: a response the engine could only pin a STATUS on
            // — a fluent `->setStatusCode(202)` over a body it could not follow — carries an unresolved
            // payload arg, and its body is exactly as undescribed as a bare response's. A void payload is
            // the opposite case: `noContent()` proves there is no body to describe.
            $payload = $type->typeArgs[0] ?? null;

            return $payload === null || $payload instanceof UnknownT ? $type->fqcn : null;
        }

        if ($bodies === []) {
            return $type->fqcn;
        }

        foreach ($bodies as $mediaType => $schema) {
            if ($mediaType === BinaryRepresentation::ANY_MEDIA_TYPE || $schema === []) {
                return $type->fqcn;
            }
        }

        return null;
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
     * redirect's `Location`, a download's `Content-Disposition` — and are written as inference, not
     * fallback, since the code stated them. `$bodies` are schemas the response class proved directly,
     * sorted so which entry is primary follows from the set and not from return-path order.
     *
     * @param  list<DType>  $payloads
     * @param  ?array<string, mixed>  $headers
     * @param  array<string, array<string, mixed>>  $bodies
     */
    private function emit(
        OperationDraft $operation,
        RouteContext $context,
        string $status,
        array $payloads,
        ?SourceLocation $location,
        ?string $producer,
        ?array $headers = null,
        array $bodies = [],
    ): void {
        $response = $operation->response($status);
        $response->setDescription(self::REASONS[$status] ?? 'OK', Contribution::fallback());

        if ($headers !== null) {
            $response->set('headers', $headers, Contribution::inference($context->actionSource()));
        }

        if ($response->isBodyless()) {
            return;
        }

        // Anchor the body to the first contributing return path (design §4); no usable location means
        // a sourceless contribution rather than a churny one.
        $source = $location !== null ? $context->sourceAt($location) : null;

        ksort($bodies);

        foreach ($bodies as $mediaType => $schema) {
            $content = $response->content($mediaType);
            foreach ($schema as $keyword => $value) {
                $content->set($keyword, $value, Contribution::inference($source));
            }
        }

        if ($payloads === []) {
            return;
        }

        foreach ($this->byMediaType($context, $payloads) as $mediaType => $group) {
            $type = count($group) === 1 ? $group[0] : UnionT::of($this->dedupe($group));
            $result = $context->converter()->toSchema($type);

            $contribution = $producer !== null
                ? Contribution::forProducer($producer, $source, $result->confidence)
                : Contribution::inference($source, $result->confidence);

            // Registered even when the payload converted to an open `{}` — an absent content entry would
            // read as "no body at all", which is the one thing inference has ruled out.
            $content = $response->content($mediaType);
            foreach ($result->schema as $keyword => $value) {
                $content->set($keyword, $value, $contribution);
            }
        }
    }

    /**
     * The status's payloads grouped by the media type each serialises as, from the gated
     * {@see PayloadMediaTypeResolver} chain — JSON:API resources answer `application/vnd.api+json`, a
     * rendered view `text/html`, everything else the default `application/json`. A return path that
     * negotiates between representations is one content entry per media type rather than one `anyOf`
     * under a media type half of it contradicts. Sorted, so which entry is primary follows from the set
     * of payloads and not from return-path order.
     *
     * @param  list<DType>  $payloads
     * @return array<string, list<DType>>
     */
    private function byMediaType(RouteContext $context, array $payloads): array
    {
        $groups = [];
        foreach ($payloads as $payload) {
            $groups[$context->payloadMediaType($payload)][] = $payload;
        }

        ksort($groups);

        return $groups;
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
