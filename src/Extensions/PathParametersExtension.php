<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Contracts\RouteBindingSchemaResolver;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Patch\Contribution;

/**
 * Adds a path parameter for every `{param}` in the route template (design §Route-model binding).
 * A parameter bound to a model is typed from the model's ROUTE KEY — uuid/ulid/int with the matching
 * format (Laravel's default `id` route key), resolved through the gated
 * {@see RouteBindingSchemaResolver} chain (contributed by the
 * Eloquent integration) so a `{model}` segment matches the model's real key rather than a hardcoded
 * integer; a disabled Eloquent integration, or an unbound segment, yields a required string. Attribute
 * `#[PathParameter]` refines these later through the higher attribute layer.
 *
 * When the route allows trashed bindings (`->withTrashed()`), each bound parameter is flagged: a note
 * is appended to its description and a stable `x-docuccino.facts.routeBinding.withTrashed` semantic
 * fact is recorded, so consumers know a soft-deleted record resolves here.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class PathParametersExtension implements OperationExtension
{
    private const TRASHED_NOTE = 'Resolves soft-deleted (trashed) records as well as active ones.';

    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        foreach ($context->pathParameters as $name) {
            $parameter = $operation->parameter('path', $name);

            $isBound = isset($context->routeBindings[$name]);
            $contribution = $isBound
                ? Contribution::inference($context->actionSource())
                : Contribution::fallback();

            $parameter->setRequired(! in_array($name, $context->optionalPathParameters, true), $contribution);

            if ($isBound) {
                // Record the bound model's file (design §10 cache soundness): switching a model to
                // HasUuids changes the route-key schema, so a warm DELETE /users/{user} fragment must
                // invalidate. Plain reflection keeps this framework-neutral (no integration import).
                $this->recordModelFile($context, $context->routeBindings[$name]);
            }

            $keySchema = $isBound ? $context->routeBindingKeySchema($context->routeBindings[$name]) : null;
            if ($keySchema !== null) {
                foreach ($keySchema as $keyword => $value) {
                    $parameter->schema()->set((string) $keyword, $value, $contribution);
                }
            } else {
                $parameter->schema()->set('type', 'string', $contribution);
            }

            if ($isBound && $context->allowsTrashedBindings) {
                $parameter->setDescription(self::TRASHED_NOTE, $contribution);
                $parameter->setDocuccinoFact('routeBinding', ['withTrashed' => true]);
            }
        }
    }

    /** Record the bound model class's file as a fragment-cache dependency, if it can be reflected. */
    private function recordModelFile(RouteContext $context, string $modelFqcn): void
    {
        if (! class_exists($modelFqcn)) {
            return;
        }

        $file = (new \ReflectionClass($modelFqcn))->getFileName();
        if ($file !== false) {
            $context->recordDependencyFiles([$file]);
        }
    }
}
