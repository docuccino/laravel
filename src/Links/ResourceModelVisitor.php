<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Links;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use PhpParser\Node;

/**
 * What the walk hands each construction of a resource — `new PostResource($post)`,
 * `PostResource::make($post)` — typed by the analyser, so which model a resource wraps is read where the
 * action builds it rather than from the resource's name. A resource the walk sees built around anything
 * but one model class — a union, a nullable find, an array — wraps nothing it can name, whatever its
 * `@mixin` says.
 *
 * @internal
 */
final class ResourceModelVisitor implements TraceVisitor
{
    /** Stands in for a construction around something that is not exactly one model. */
    private const string UNKNOWN = "\0";

    /** @var array<string, array<string, true>> resource → the model classes it was built around */
    private array $wrapped = [];

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        $argument = self::wrapped($node);
        $resource = $argument !== null && $node instanceof Node\Expr ? $scope->typeOf($node) : null;
        if ($argument !== null && $resource instanceof ClassT && ResourceReflector::isResource($resource->fqcn) && ! ResourceReflector::isCollection($resource->fqcn)) {
            $type = $scope->typeOf($argument);
            $model = $type instanceof ClassT && EloquentModelReflector::isModel($type->fqcn) ? $type->fqcn : self::UNKNOWN;
            $this->wrapped[$resource->fqcn][$model] = true;
        }

        // Descend into any app-code call, so a resource a helper builds is seen too.
        return $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall;
    }

    /** The one model every construction of `$resource` the walk saw was built around, or null. */
    public function modelOf(string $resource): ?string
    {
        $models = array_keys($this->wrapped[$resource] ?? []);

        return count($models) === 1 && $models[0] !== self::UNKNOWN ? $models[0] : null;
    }

    /**
     * The model `$resource` wraps as a body: what the walk saw it built around, and only where the walk
     * saw no construction of it at all, the model its `@mixin` names. A construction around anything but
     * one model is an answer — none — that a docblock written for the IDE must not overrule.
     */
    public function wrappedModel(string $resource): ?string
    {
        return isset($this->wrapped[$resource]) ? $this->modelOf($resource) : BodyColumns::mixin($resource);
    }

    /** What a construction hands the resource to wrap, or null where `$node` builds none. */
    private static function wrapped(Node $node): ?Node\Expr
    {
        $builds = ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name)
            || ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier && $node->name->toString() === 'make');

        if (! $builds) {
            return null;
        }

        // The resource is the first argument: positional, or by the name its constructor gives it.
        foreach ($node->getArgs() as $position => $arg) {
            if ($arg->unpack) {
                return null;
            }
            if ($arg->name === null ? $position === 0 : $arg->name->toString() === 'resource') {
                return $arg->value;
            }
        }

        return null;
    }
}
