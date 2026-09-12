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
 * Maps a polymorphic morph — the union of two or more models that `MorphTo<Post|Video>` resolves to —
 * into a `oneOf` of the variant schemas. Runs ahead of the core union mapper so a model union gets
 * `oneOf` rather than a bare `anyOf`; a nullable morph keeps its `null` branch.
 *
 * A `discriminator` (propertyName `type`, mapping from `Relation::morphMap()`) needs EVERY variant to be
 * mapped AND referenceable. If one isn't, the mapper emits a bare `oneOf` — a partial mapping with
 * unstable FQCN values would make a client mis-parse, which is worse than none. An unregistered alias is
 * the author's to fix and gets an info diagnostic per variant; a variant with no `$ref` to map onto is
 * not, so it is dropped silently.
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
            $ref = $context->convertMember($model);
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

        // Every variant, or none: a mapping short of the variant list sends a client to the wrong schema
        // for the aliases it omits, which is worse than leaving them to match the `oneOf` themselves. It
        // falls short two ways — an alias nothing registered (reported above), and a variant another
        // mapper published inline, which has no `$ref` to map an alias onto and is nobody's to fix.
        $schema = ['oneOf' => $variants];
        if ($allMapped && count($mapping) === count($models)) {
            $schema['discriminator'] = ['propertyName' => self::DISCRIMINATOR_PROPERTY, 'mapping' => $mapping];
        }

        return new SchemaResult($schema, 0.9);
    }

    /**
     * The union's model members — the morph variants — in declaration order.
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

    /** The alias a model serialises its `type` as, or null when unmapped. */
    private function morphAlias(string $fqcn): ?string
    {
        $alias = array_search($fqcn, Relation::morphMap(), true);

        return is_string($alias) ? $alias : null;
    }
}
