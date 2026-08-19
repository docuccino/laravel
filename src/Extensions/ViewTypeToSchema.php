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
use Docuccino\Laravel\Support\HtmlRepresentation;

/**
 * Maps a rendered view to the HTML body it becomes ({@see HtmlRepresentation}) rather than letting core's
 * class mapper reflect it into a component of `factory`, `engine` and `path`. `EARLY` for the same reason
 * {@see FrameworkResponseTypeToSchema} is: it has to get there before that mapper.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class ViewTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof ClassT && FrameworkClasses::isView($type->fqcn);
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $this->supports($type)) {
            return null;
        }

        // Full confidence: the shape is not a recovery that fell short, it is all a rendered template has.
        return new SchemaResult(HtmlRepresentation::SCHEMA);
    }
}
