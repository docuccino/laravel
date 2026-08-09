<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\TimacdonaldJsonApi;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Laravel\Integrations\Support\JsonApiParameters;

/**
 * Adds the JSON:API query parameters the package resolves — `include` for compound documents, `fields[TYPE]`
 * for sparse fieldsets — to any operation returning a timacdonald resource or collection, via the shared
 * {@see JsonApiParameters} applier.
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
