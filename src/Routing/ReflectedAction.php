<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Core\Inference\ActionRef;
use ReflectionFunctionAbstract;

/**
 * A route's action resolved to the pieces both the resolver (attribute filtering) and the
 * context builder (attribute collection, inference) need: the engine {@see ActionRef}, the
 * PHP reflection of the handler, and the controller/invokable FQCN (null for closure routes).
 *
 * @internal
 */
final readonly class ReflectedAction
{
    public function __construct(
        public ActionRef $actionRef,
        public ReflectionFunctionAbstract $reflection,
        public ?string $controllerClass,
    ) {}
}
