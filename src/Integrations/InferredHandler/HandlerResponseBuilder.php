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
 * Builds an error {@see ResponseDraft} from a handler/closure analysis (design §6): reads the recovered
 * `JsonResponse<TPayload, TStatus, TContentType>` for the real status, payload shape and content type
 * (default `application/json`, `application/problem+json` when the helper set that header), then hoists
 * the payload schema through the route's converter.
 *
 * The example carries only members that folded to a literal — including a {@see StatusMarkerT} member (a
 * value echoing the response status) resolved to this response's status, so the 403 arm says `403`.
 * Nothing else is invented. A status that didn't fold falls back to the exception's own status hint
 * rather than 200; a payload that didn't fold ({@see UnknownT}) drops the body schema but keeps the
 * status and media type.
 *
 * Null means no `JsonResponse` was recovered: either a `return null`/void arm ({@see isDelegation()} —
 * the renderer handing the type back to the framework, not a fold failure) or a body too dynamic to
 * fold. Reason phrases come from {@see FrameworkExceptionTable} so this tier can't drift from the others.
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
     * Every recovered return is a `return null`/void arm, i.e. the renderer delegates to the framework.
     * The tier defers silently on these rather than raising a too-dynamic deferral.
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

        // Didn't fold (e.g. an enum method result) — prefer the exception's own classification to 200.
        return (string) ($statusHint ?? 200);
    }

    private static function contentType(mixed $contentTypeArg): string
    {
        return $contentTypeArg instanceof LiteralT && is_string($contentTypeArg->value)
            ? $contentTypeArg->value
            : 'application/json';
    }

    /**
     * Pin each top-level status-echo member to the status this response is documented under, so it
     * converts to a `const` integer and lands in the example. Non-shape payloads pass through.
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
     * Only top-level members that folded to a literal go in; widened scalars, dynamic bodies and nested
     * shapes are omitted rather than invented. Declaration order, so it's deterministic. Empty → no
     * example emitted.
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
