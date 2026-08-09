<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use PhpParser\Node;

/**
 * The shared paginating-terminal detector, used by the Query-Builder, json-api-paginate and response-body
 * consumers. Finds a configured terminal on any query-builder receiver at any chain depth, or the
 * magic-static `Model::paginate(...)` form, and records the OUTERMOST one's kind.
 *
 * Why it has to be a call-graph fact: a resource collection's static return type is
 * `AnonymousResourceCollection<T>` whether or not it was paginated, so nothing in the type tells you
 * that `UserResource::collection($query->paginate())` carries the `{data, links, meta}` envelope.
 */
final class PaginationTerminalVisitor implements TraceVisitor
{
    /**
     * Bases a paginating terminal is a method of — Eloquent and base query builders, relations, and
     * Spatie's Query Builder, which forwards to them.
     *
     * @var list<string>
     */
    private const BUILDERS = [
        'Illuminate\\Database\\Eloquent\\Builder',
        'Illuminate\\Database\\Query\\Builder',
        'Illuminate\\Database\\Eloquent\\Relations\\Relation',
        'Spatie\\QueryBuilder\\QueryBuilder',
    ];

    /**
     * Laravel's terminals → paginator kind, defined once for every consumer. Custom or package terminals
     * extend it with their own kind.
     *
     * @var array<string, string>
     */
    public const PAGINATOR_TERMINALS = [
        'paginate' => 'length',
        'simplePaginate' => 'simple',
        'cursorPaginate' => 'cursor',
    ];

    public bool $paginates = false;

    /** The outermost terminal's paginator kind: length | simple | cursor. */
    public ?string $kind = null;

    /**
     * The outermost call's positional arguments, each folded to an int or null — e.g.
     * `jsonPaginate($maxResults, $defaultSize)`. For consumers recovering a per-call-site size override.
     *
     * @var list<?int>
     */
    public array $outermostArgs = [];

    /**
     * @param  array<string, string>  $terminals  terminal method name → paginator kind
     */
    public function __construct(private readonly array $terminals) {}

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        if ($node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && isset($this->terminals[$node->name->toString()])
            && $this->receiverIsBuilder($node->var, $scope)
        ) {
            $this->record($node->name->toString(), $node, $scope);
        }

        // `Model::paginate(...)` — the terminal is the builder's method, reached magically off the model.
        if ($node instanceof Node\Expr\StaticCall
            && $node->name instanceof Node\Identifier
            && isset($this->terminals[$node->name->toString()])
            && $this->classIsModel($node->class, $scope)
        ) {
            $this->record($node->name->toString(), $node, $scope);
        }

        // Descend into any app-code call so a terminal built inside a helper is reached; the engine
        // declines vendor / magic / over-budget descent on its own.
        return $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall;
    }

    private function record(string $terminal, Node\Expr\MethodCall|Node\Expr\StaticCall $call, TypeScope $scope): void
    {
        // The engine walks the entry method before descending, so the shallowest call site wins.
        if ($this->paginates) {
            return;
        }

        $this->paginates = true;
        $this->kind = $this->terminals[$terminal];

        $args = [];
        foreach ($call->getArgs() as $arg) {
            $value = $scope->constantValueOf($arg->value);
            $args[] = $value !== null && $value->isScalar() && is_int($value->scalar) ? $value->scalar : null;
        }
        $this->outermostArgs = $args;
    }

    private function receiverIsBuilder(Node\Expr $receiver, TypeScope $scope): bool
    {
        $type = $scope->typeOf($receiver);
        if (! $type instanceof ClassT) {
            return false;
        }

        foreach (self::BUILDERS as $builder) {
            if (is_a($type->fqcn, $builder, true)) {
                return true;
            }
        }

        return false;
    }

    private function classIsModel(Node\Name|Node\Expr $class, TypeScope $scope): bool
    {
        if ($class instanceof Node\Name) {
            return EloquentModelReflector::isModel($class->toString());
        }

        $type = $scope->typeOf($class);

        return $type instanceof ClassT && EloquentModelReflector::isModel($type->fqcn);
    }
}
