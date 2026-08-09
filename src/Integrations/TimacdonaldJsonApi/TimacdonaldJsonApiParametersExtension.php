<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\TimacdonaldJsonApi;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Laravel\Integrations\Support\JsonApiParameters;

/**
 * Adds the JSON:API query parameters `timacdonald/json-api` resolves — `include` (compound documents)
 * and `fields[TYPE]` (sparse fieldsets) — to any operation whose action returns a timacdonald JSON:API
 * resource or collection ({@see TimacdonaldResourceReflector::involvesJsonApi()}), via the shared
 * {@see JsonApiParameters} applier. Guarded (with the schema mapper) behind `class_exists`, so it
 * never registers when the package is absent.
 */
final class TimacdonaldJsonApiParametersExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        JsonApiParameters::applyIf($operation, $context, TimacdonaldResourceReflector::involvesJsonApi(...), 'timacdonald-json-api');
    }
}
