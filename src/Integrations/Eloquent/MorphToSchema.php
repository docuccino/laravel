<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnionT;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Maps a polymorphic morph — a union of two or more Eloquent models, as `MorphTo<Post|Video>`
 * surfaces once inference resolves the related type — to an OAS `oneOf` of the variant schemas.
 * A `discriminator` (propertyName `type`, `mapping` from `Relation::morphMap()`) is emitted ONLY
 * when polymorphism is evidenced — every variant has a morph-map alias — so clients get a stable,
 * complete mapping they can trust. If any variant is unmapped the mapper emits a bare `oneOf`
 * (no discriminator) and raises an info diagnostic per unmapped variant: a partial discriminator
 * whose values are unstable FQCNs would mislead a client into mis-parsing, worse than none.
 * Runs ahead of the core union mapper so a model union becomes `oneOf` rather than a bare `anyOf`;
 * a nullable morph keeps a `null` branch.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class MorphToSchema implements TypeToSchema
{
    private const DISCRIMINATOR_PROPERTY = 'type';

    public function supports(DType $type): bool
    {
        return $type instanceof UnionT && count($this->modelMembers($type)) >= 2;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof UnionT) {
            return null;
        }

        $models = $this->modelMembers($type);
        if (count($models) < 2) {
            return null;
        }

        $variants = [];
        $mapping = [];
        $allMapped = true;
        foreach ($models as $model) {
            $ref = $context->convert($model);
            $variants[] = $ref;

            $alias = $this->morphAlias($model->fqcn);
            if ($alias === null) {
                $allMapped = false;
                $context->diagnostic(new Diagnostic(
                    severity: Severity::Info,
                    code: 'eloquent.unmapped-morph',
                    message: sprintf('Morph variant %s has no Relation::morphMap() alias; the union is emitted as a bare oneOf without a discriminator.', $model->fqcn),
                    help: 'Register an alias in Relation::enforceMorphMap([...]) for every variant so a stable discriminator can be emitted.',
                ));

                continue;
            }

            if (is_string($ref['$ref'] ?? null)) {
                $mapping[$alias] = $ref['$ref'];
            }
        }

        if ($type->containsNull()) {
            $variants[] = ['type' => 'null'];
        }

        $schema = ['oneOf' => $variants];
        // A discriminator is only sound when every variant is morph-mapped: a partial mapping (or
        // FQCN-valued keys) would let a client mis-parse an unmapped variant (arch I3).
        if ($allMapped && $mapping !== []) {
            $schema['discriminator'] = ['propertyName' => self::DISCRIMINATOR_PROPERTY, 'mapping' => $mapping];
        }

        return new SchemaResult($schema, 0.9);
    }

    /**
     * The Eloquent-model members of a union (morph variants), preserving declaration order.
     *
     * @return list<ClassT>
     */
    private function modelMembers(UnionT $type): array
    {
        $models = [];
        foreach ($type->members as $member) {
            if ($member instanceof ClassT && EloquentModelReflector::isModel($member->fqcn)) {
                $models[] = $member;
            }
        }

        return $models;
    }

    /** The morph-map alias a model serialises its type as, or null when it is unmapped. */
    private function morphAlias(string $fqcn): ?string
    {
        $alias = array_search($fqcn, Relation::morphMap(), true);

        return is_string($alias) ? $alias : null;
    }
}
