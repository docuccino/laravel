<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Extensions\Contracts\PayloadMediaTypeResolver;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Support\FrameworkClasses;
use Docuccino\Laravel\Support\HtmlRepresentation;

/**
 * The other half of documenting a view: a rendered view serialises as `text/html`
 * ({@see HtmlRepresentation}), never as the default `application/json`. Anything else defers.
 */
final class ViewMediaType implements PayloadMediaTypeResolver
{
    public function mediaTypeFor(DType $payload): ?string
    {
        return $payload instanceof ClassT && FrameworkClasses::isView($payload->fqcn)
            ? HtmlRepresentation::MEDIA_TYPE
            : null;
    }
}
