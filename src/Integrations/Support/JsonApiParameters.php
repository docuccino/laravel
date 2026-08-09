<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Patch\Contribution;

/**
 * The shared applier for the two JSON:API query parameters both JSON:API resource families resolve —
 * `include` (compound documents) and `fields[TYPE]` (sparse fieldsets). Laravel's first-party
 * `JsonApiRequest` and `timacdonald/json-api`'s `Support\Includes`/`Support\Fields` read the same
 * parameter shapes, so each integration's parameters extension supplies its own family predicate +
 * producer and defers both the JsonResponse-unwrapping guard and the actual parameter writes here.
 */
final class JsonApiParameters
{
    /**
     * Apply the JSON:API params when any of the action's return types (JsonResponse-unwrapped) is a
     * JSON:API resource per the given family predicate, attributed to `integration:<producer>`.
     *
     * @param  callable(DType): bool  $involvesJsonApi
     */
    public static function applyIf(OperationDraft $operation, RouteContext $context, callable $involvesJsonApi, string $producer): void
    {
        foreach ($context->analysis()->returns as $return) {
            if ($involvesJsonApi(self::unwrap($return->type))) {
                self::apply($operation, Contribution::integration($producer, $context->actionSource()));

                return;
            }
        }
    }

    public static function apply(OperationDraft $operation, Contribution $contribution): void
    {
        $include = $operation->parameter('query', 'include');
        $include->setDescription('Comma-separated list of relationships to include as compound-document data.', $contribution);
        $include->setRequired(false, $contribution);
        $include->schema()->set('type', 'string', $contribution);

        // Sparse fieldsets: fields[TYPE]=a,b — a deepObject of comma-separated strings keyed by type.
        // On an endpoint that is BOTH a Spatie-QB list and a JSON:API resource, this `fields` param
        // collides with QB's allowedFields `fields`: the pipeline merges parameters by (in + name), so
        // one write shadows the other (later layer wins) with an info diagnostic — acceptable, no dedup.
        $fields = $operation->parameter('query', 'fields');
        $fields->setDescription('Sparse fieldsets per resource type (fields[TYPE]=field1,field2).', $contribution);
        $fields->setRequired(false, $contribution);
        $fields->set('style', 'deepObject', $contribution);
        $fields->set('explode', true, $contribution);
        $fields->schema()->set('type', 'object', $contribution);
        $fields->schema()->set('additionalProperties', ['type' => 'string'], $contribution);
    }

    /** Unwrap a `JsonResponse<payload>` to its payload type; other types pass through. */
    private static function unwrap(DType $type): DType
    {
        if ($type instanceof ClassT && $type->fqcn === FrameworkClasses::JSON_RESPONSE) {
            return $type->typeArgs[0] ?? $type;
        }

        return $type;
    }
}
