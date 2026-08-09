<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Laravel\Integrations\Support\JsonApiParameters;

/**
 * Adds the JSON:API query parameters Laravel's `JsonApiRequest` resolves — `include` (compound
 * documents) and `fields[TYPE]` (sparse fieldsets) — to any operation whose action returns a
 * first-party JSON:API resource or collection ({@see ResourceReflector::involvesJsonApi()}), via the
 * shared {@see JsonApiParameters} applier. Guarded (with {@see JsonApiResourceSchema}) behind
 * `class_exists`, so it never registers on older Laravel.
 */
final class JsonApiParametersExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        JsonApiParameters::applyIf($operation, $context, ResourceReflector::involvesJsonApi(...), 'api-resources');
    }
}
