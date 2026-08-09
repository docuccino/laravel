<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Support\FrameworkClasses;
use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;

/**
 * Turns a handler/closure analysis into an error response (design §6). Reads the recovered
 * `JsonResponse<TPayload, TStatus, TContentType>` — the handler's REAL rendered status, payload shape
 * and (through the engine's helper-indirection refinement) content type — and builds a
 * {@see ResponseDraft} under that status, hoisting the payload
 * schema through the route's converter under the recovered media type (default `application/json`,
 * `application/problem+json` when the helper set that header).
 *
 * It also assembles a media-type EXAMPLE from the statically-known members: a body member the engine
 * folded to a literal (a per-arm `type` URI) contributes its value and a `const`, and a status-provenance
 * member ({@see StatusMarkerT} — a value that echoes the response status) is filled with THIS response's
 * concrete status (the 403 response says `403`, the 404 says `404`). Every non-folding member is omitted,
 * never fabricated; deterministic by construction (declaration order, no randomness).
 *
 * HONESTY where a fold was partial: when the status did not fold to a literal — an enum-derived or
 * otherwise dynamic status the refiner recovered as {@see UnknownT} — the status falls back to the
 * exception's own status hint (the throw-analysis classification) rather than guessing 200. A payload
 * the refiner could not fold ({@see UnknownT}) contributes no body schema (the media type + status are
 * still documented).
 *
 * Returns null when the analysis recovered no `JsonResponse` at all: a `return null` / void arm (the
 * renderer DELEGATES the type to the framework — {@see isDelegation()} tells the tier this is benign,
 * not a fold failure) or a body too dynamic to fold. Reason phrases come from the shared
 * {@see FrameworkExceptionTable} so the inferred-handler tier can never drift from the others.
 */
final class HandlerResponseBuilder
{
    public static function build(
        ActionAnalysis $analysis,
        RouteContext $context,
        Contribution $contribution,
        ?int $statusHint = null,
    ): ?ResponseDraft {
        foreach ($analysis->returns as $return) {
            $type = $return->type;
            if (! $type instanceof ClassT || $type->fqcn !== FrameworkClasses::JSON_RESPONSE) {
                continue;
            }

            $status = self::foldStatus($type->typeArgs[1] ?? null, $statusHint);
            $draft = new ResponseDraft($status);
            $draft->setDescription(FrameworkExceptionTable::reason($status), $contribution);

            $payload = $type->typeArgs[0] ?? null;
            if ($payload !== null && ! $payload instanceof VoidT && ! $payload instanceof NeverT && ! $payload instanceof UnknownT) {
                $mediaType = self::contentType($type->typeArgs[2] ?? null);
                // A status-provenance member echoes THIS response's status: resolve it to that concrete
                // value so its schema is `const`-pinned and it lands in the example (the 403 → 403).
                $payload = self::resolveStatusMarkers($payload, (int) $status);
                foreach ($context->converter()->toSchema($payload)->schema as $keyword => $value) {
                    $draft->content($mediaType)->set($keyword, $value, $contribution);
                }
                $example = self::assembleExample($payload);
                if ($example !== []) {
                    $draft->setExample($mediaType, $example);
                }
            }

            return $draft;
        }

        return null;
    }

    /**
     * Whether the analysis is a framework DELEGATION rather than a fold failure — every recovered
     * return is a `return null` / void arm (the renderer hands the type back to the framework). The
     * tier defers silently for these instead of raising a too-dynamic deferral.
     */
    public static function isDelegation(ActionAnalysis $analysis): bool
    {
        if ($analysis->returns === []) {
            return false;
        }

        foreach ($analysis->returns as $return) {
            if (! $return->type instanceof VoidT && ! $return->type instanceof NullT) {
                return false;
            }
        }

        return true;
    }

    private static function foldStatus(mixed $statusArg, ?int $statusHint): string
    {
        if ($statusArg instanceof LiteralT && is_int($statusArg->value)) {
            return (string) $statusArg->value;
        }

        // The status did not fold to a literal (e.g. an enum method result); fall back to the
        // exception's own status classification before the final 200 default — never guess.
        return (string) ($statusHint ?? 200);
    }

    private static function contentType(mixed $contentTypeArg): string
    {
        return $contentTypeArg instanceof LiteralT && is_string($contentTypeArg->value)
            ? $contentTypeArg->value
            : 'application/json';
    }

    /**
     * Resolve every top-level {@see StatusMarkerT} body member — a value that echoes the response's own
     * HTTP status — to the concrete status this response is documented under, so it converts to a
     * `const`-pinned integer and appears in the example. Non-shape / list payloads pass through.
     */
    private static function resolveStatusMarkers(DType $payload, int $status): DType
    {
        if (! $payload instanceof ArrayShapeT) {
            return $payload;
        }

        return $payload->mapFieldTypes(
            static fn (DType $type): DType => $type instanceof StatusMarkerT ? new LiteralT($status) : $type,
        );
    }

    /**
     * Assemble the media-type example from SOUND members only: each top-level member folded to a literal
     * (a per-arm `type` URI, a `status` that resolved) contributes its value; every other member — a
     * widened scalar, a dynamic body, a nested shape — is OMITTED rather than fabricated. Deterministic
     * by construction: declaration order, no randomness. Empty (no sound members) → no example emitted.
     *
     * @return array<string, string|int|float|bool>
     */
    private static function assembleExample(DType $payload): array
    {
        if (! $payload instanceof ArrayShapeT || $payload->isList) {
            return [];
        }

        $example = [];
        foreach ($payload->fields as $field) {
            if ($field->type instanceof LiteralT) {
                $example[(string) $field->key] = $field->type->value;
            }
        }

        return $example;
    }
}
