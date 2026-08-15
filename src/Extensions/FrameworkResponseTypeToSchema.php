<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Support\FrameworkClasses;

/**
 * Refuses a framework response object as a body — an open `{}` at low confidence, never a component of
 * the response's PHP internals ({@see FrameworkClasses::isResponse()} owns the list). `EARLY` keeps it
 * ahead of core's class mapper, for the reason recorded where it's registered in `DefaultExtensions`.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class FrameworkResponseTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof ClassT && FrameworkClasses::isResponse($type->fqcn);
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $this->supports($type)) {
            return null;
        }

        return new SchemaResult([], 0.1);
    }
}
