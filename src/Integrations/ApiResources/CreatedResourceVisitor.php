<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use PhpParser\Node;

/**
 * Detects the statically-clear "created model" resource pattern — a resource constructed directly
 * around a fresh `Model::create(...)` / `Model::forceCreate(...)` result (`new UserResource(User::create($data))`
 * or `UserResource::make(User::create($data))`). Those factory methods set `wasRecentlyCreated`, so
 * Laravel's `ResourceResponse::calculateStatus()` answers 201, not 200 (audit api-resources #12).
 *
 * Scope is deliberately narrow: only the resource-directly-wraps-create form is recovered. A model
 * created into a variable first, `save()` on a `new` instance, or a conditional create degrade
 * silently to the default 200 — the honest limit of static visibility.
 */
final class CreatedResourceVisitor implements TraceVisitor
{
    /** Eloquent factory methods that unconditionally set `wasRecentlyCreated`. */
    private const CREATE_METHODS = ['create', 'forceCreate'];

    public bool $created = false;

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        // `new ResourceClass(Model::create(...))`
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            if (ResourceReflector::isResource($node->class->toString()) && $this->firstArgCreates($node->getArgs())) {
                $this->created = true;
            }
        }

        // `ResourceClass::make(Model::create(...))`
        if ($node instanceof Node\Expr\StaticCall
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'make'
            && $node->class instanceof Node\Name
            && ResourceReflector::isResource($node->class->toString())
            && $this->firstArgCreates($node->getArgs())
        ) {
            $this->created = true;
        }

        return true;
    }

    /**
     * @param  array<array-key, Node\Arg>  $args
     */
    private function firstArgCreates(array $args): bool
    {
        $first = $args[0]->value ?? null;

        return $first instanceof Node\Expr\StaticCall
            && $first->name instanceof Node\Identifier
            && in_array($first->name->toString(), self::CREATE_METHODS, true)
            && $first->class instanceof Node\Name
            && EloquentModelReflector::isModel($first->class->toString());
    }
}
