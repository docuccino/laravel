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
 * Adds a path parameter for every `{param}` in the route template (design §Route-model binding). A
 * model-bound parameter is typed from the model's route key (uuid/ulid/int, with format) via the gated
 * {@see RouteBindingSchemaResolver} chain, so `{model}` matches the real key instead of a hardcoded
 * integer. An unbound segment — or a disabled Eloquent integration — gives a required string, and
 * `#[PathParameter]` can refine any of it from the higher attribute layer.
 *
 * A route with `->withTrashed()` flags each bound parameter: a note on the description plus an
 * `x-docuccino.facts.routeBinding.withTrashed` fact, so consumers know soft-deleted records resolve.
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
                // Switching a model to HasUuids changes the route-key schema, so a warm fragment has to
                // invalidate (design §10).
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

    /** Records the model's file as a cache dependency, when it can be reflected. */
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
