<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use PhpParser\Node;

/**
 * The shared paginating-terminal detector — the generalised form of the trace/terminal machinery the
 * Query-Builder and json-api-paginate integrations use, so a paginated RESPONSE body can be recognised
 * without duplicating that recovery a third time. It finds a configured paginating terminal
 * (`paginate`/`simplePaginate`/`cursorPaginate`, or `jsonPaginate`) on any query-builder receiver at
 * any chain depth (the engine descends into app-code helpers), or the magic-static
 * `Model::paginate(...)` form, and records the OUTERMOST one's kind (`length`/`simple`/`cursor`).
 *
 * The static return type of a resource collection is identical whether or not it was paginated —
 * `AnonymousResourceCollection<T>` either way — so this call-graph fact is the only signal that a
 * `UserResource::collection($query->paginate())` response carries the `{data, links, meta}` envelope.
 */
final class PaginationTerminalVisitor implements TraceVisitor
{
    /**
     * The query-builder base classes a paginating terminal is a method of (Eloquent + base query
     * builder, the JSON:API relations, and Spatie's Query Builder which forwards to them).
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
     * The single Laravel paginating-terminal → paginator-kind table, shared by every consumer (QB
     * parameters, the resource envelope, json-api-paginate) so the terminal→kind mapping is defined
     * exactly once. Custom / package terminals extend it with their own kind.
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
     * The outermost terminal call's positional arguments, each constant-folded to an int when it
     * folds to a literal (else null) — e.g. `jsonPaginate($maxResults, $defaultSize)`. Read by
     * consumers that recover a per-call-site size override; empty when the terminal takes no args.
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

        // The magic-static `Model::paginate(...)` form: the terminal is a method of the model's builder,
        // reached statically off the model class.
        if ($node instanceof Node\Expr\StaticCall
            && $node->name instanceof Node\Identifier
            && isset($this->terminals[$node->name->toString()])
            && $this->classIsModel($node->class, $scope)
        ) {
            $this->record($node->name->toString(), $node, $scope);
        }

        // Descend into any app-code call so a terminal built inside a helper is reached; the engine
        // declines vendor / magic / over-budget descent on its own (Spike B split).
        return $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall;
    }

    private function record(string $terminal, Node\Expr\MethodCall|Node\Expr\StaticCall $call, TypeScope $scope): void
    {
        // The outermost terminal is recorded first (the engine walks the entry method before
        // descending), so the shallowest call site wins.
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
