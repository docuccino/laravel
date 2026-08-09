<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use BackedEnum;
use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\FollowsReturnType;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;
use PhpParser\Comment;
use PhpParser\Node;

/**
 * Harvests a `Spatie\QueryBuilder\QueryBuilder` chain into {@see QueryBuilderFacts}. Pure semantics
 * over {@see TypeScope} — imports zero PHPStan. Works off a builder receiver at any chain depth, since
 * the engine descends into app-code helpers (so `ListQueryBuilder::for()` two levels deep still works).
 *
 * It recovers the subject model from `for(Article::class)` or `for(Article::query())` — that model's
 * column casts are what type the exact filters — plus the `allowed*` lists, `defaultSort(s)`, and the
 * paginating terminal (per-page folded from the OUTERMOST call site).
 *
 * Allow-list entries are folded at the AST level, which is the whole point: by the time PHPStan is done
 * with `AllowedFilter::exact('status')` it's a plain object type with the arguments gone. Folding here
 * keeps the internal column (`exact('status', 'status_code')`), constant `->default()`/`->nullable()`
 * modifiers, and a comment sitting directly above the entry.
 *
 * Anything un-foldable lands on {@see QueryBuilderFacts::$unresolved} with its source location, so a
 * dynamic chain becomes a named diagnostic rather than a silent omission.
 */
final class QueryBuilderTraceVisitor implements FollowsReturnType, TraceVisitor
{
    private const QUERY_BUILDER = 'Spatie\\QueryBuilder\\QueryBuilder';

    private const ALLOWED_FILTER = 'Spatie\\QueryBuilder\\AllowedFilter';

    /**
     * Config method → the allow-list it fills and the default kind for a bare-string entry.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const CONFIG_METHODS = [
        'allowedFilters' => ['filters', 'default'],
        'allowedSorts' => ['sorts', 'default'],
        'allowedIncludes' => ['includes', 'default'],
        'allowedFields' => ['fields', 'field'],
        'defaultSort' => ['defaultSorts', 'default'],
        'defaultSorts' => ['defaultSorts', 'default'],
    ];

    /**
     * Terminal method → paginator kind. Custom terminals (config) default to length-aware.
     *
     * @var array<string, string>
     */
    private array $terminals;

    /**
     * Where each factory puts the internal column name. It's normally argument 2
     * (`AllowedFilter::exact('status', 'status_code')`), but `callback`/`custom` hold a closure/instance
     * there, and `operator` a `FilterOperator` plus a boolean, pushing it further right.
     *
     * @var array<string, int>
     */
    private const INTERNAL_ARG_INDEX = [
        'callback' => 2,
        'custom' => 2,
        'operator' => 3,
    ];

    /** `FilterOperator` cases that compare for equality, so the value types off the column like `exact`. */
    private const STATIC_OPERATORS = ['DYNAMIC', 'EQUAL'];

    /**
     * @param  list<string>  $customTerminals  extra paginating terminals (length-aware), e.g. `paginateList`
     */
    public function __construct(
        public readonly QueryBuilderFacts $facts = new QueryBuilderFacts,
        array $customTerminals = [],
        private readonly WhereColumnAnalyzer $whereColumns = new WhereColumnAnalyzer,
    ) {
        $terminals = PaginationTerminalVisitor::PAGINATOR_TERMINALS;
        foreach ($customTerminals as $terminal) {
            $terminals[$terminal] ??= 'length';
        }
        $this->terminals = $terminals;
    }

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $this->visitMethodCall($node, $node->name->toString(), $scope);
        }

        // `for(…)` is the chain's origin — the subject model comes from there.
        if ($node instanceof Node\Expr\StaticCall
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'for'
        ) {
            $this->recoverSubject($node, $scope);
        }

        // Descend into any app-code call so allow-lists built inside a helper are reached; the engine
        // declines vendor / magic / over-budget descent on its own.
        return $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall;
    }

    /**
     * Follows a callee returning a `QueryBuilder` subclass even outside the configured project paths —
     * that's the `$query->query(): InvoiceQueryBuilder` hop where the whole chain often lives. Vendor is
     * still never descended into, so this reaches the app's Query class, not the package's own methods.
     */
    public function followsReturnType(DType $returnType): bool
    {
        return $returnType instanceof ClassT && is_a($returnType->fqcn, self::QUERY_BUILDER, true);
    }

    private function visitMethodCall(Node\Expr\MethodCall $node, string $name, TypeScope $scope): void
    {
        if (! $this->receiverIsBuilder($node->var, $scope)) {
            return;
        }

        if (isset(self::CONFIG_METHODS[$name])) {
            [$bucket, $defaultKind] = self::CONFIG_METHODS[$name];
            $this->harvest($node, $scope, $bucket, $defaultKind, $name);
        }

        if (isset($this->terminals[$name])) {
            $this->recordTerminal($node, $name, $scope);
        }
    }

    /**
     * The subject model behind a `for(…)` origin. First `for()` reached wins (there's one per chain); an
     * unresolvable subject leaves {@see QueryBuilderFacts::$subjectModel} null and every filter a string.
     */
    private function recoverSubject(Node\Expr\StaticCall $node, TypeScope $scope): void
    {
        if ($this->facts->subjectModel !== null) {
            return;
        }

        $type = $scope->typeOf($node);
        if (! ($type instanceof ClassT && is_a($type->fqcn, self::QUERY_BUILDER, true))) {
            return;
        }

        $args = $node->getArgs();
        if (! isset($args[0])) {
            return;
        }

        $this->facts->subjectModel = $this->subjectFromArg($args[0]->value, $scope);
    }

    /**
     * A `Model::class` const string, else the argument's own model type — `for($query)` /
     * `for(Model::query())` give a builder or relation carrying the model as a generic arg.
     */
    private function subjectFromArg(Node\Expr $arg, TypeScope $scope): ?string
    {
        $const = $scope->constantValueOf($arg);
        if ($const !== null && $const->isScalar() && is_string($const->scalar) && $const->scalar !== '') {
            return $const->scalar;
        }

        $type = $scope->typeOf($arg);
        if (! $type instanceof ClassT) {
            return null;
        }

        if (EloquentModelReflector::isModel($type->fqcn)) {
            return $type->fqcn;
        }

        foreach ($type->typeArgs as $typeArg) {
            if ($typeArg instanceof ClassT && EloquentModelReflector::isModel($typeArg->fqcn)) {
                return $typeArg->fqcn;
            }
        }

        return null;
    }

    private function harvest(Node\Expr\MethodCall $node, TypeScope $scope, string $bucket, string $defaultKind, string $method): void
    {
        foreach ($node->getArgs() as $arg) {
            $value = $arg->value;
            if ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $item) {
                    $this->collect($item->value, $item, $bucket, $defaultKind, $method, $scope);
                }

                continue;
            }

            $this->collect($value, null, $bucket, $defaultKind, $method, $scope);
        }
    }

    /** Folds one allow-list expression into an entry, or records it unresolved. */
    private function collect(Node\Expr $expr, ?Node $itemNode, string $bucket, string $defaultKind, string $method, TypeScope $scope): void
    {
        [$base, $hasDefault, $default, $nullable] = $this->peelModifiers($expr, $scope);

        $value = $scope->constantValueOf($base);
        $entry = $value === null ? null : $this->entryFor($value, $defaultKind);
        if ($entry === null) {
            $location = $scope->location($expr);
            $this->facts->unresolved[] = sprintf('%s entry at %s:%d', $method, $location->file, $location->line);

            return;
        }

        // Only filters carry column typing or a custom-filter class; sorts/includes/fields never do.
        [$typeColumn, $filterClass, $factoryEnum, $factoryClass] = $bucket === 'filters'
            ? $this->filterTyping($entry, $value, $base, $scope)
            : [null, null, null, null];

        $entry = new QbEntry(
            $entry->name,
            $entry->kind,
            $entry->internal,
            $hasDefault,
            $default,
            $nullable,
            $itemNode !== null ? $this->leadingComment($itemNode) : null,
            typeColumn: $typeColumn,
            filterClass: $filterClass,
            factoryEnum: $factoryEnum,
            factoryClass: $factoryClass,
        );

        if ($bucket === 'defaultSorts') {
            $this->facts->defaultSorts[] = $entry->name;

            return;
        }

        match ($bucket) {
            'filters' => $this->facts->filters[] = $entry,
            'sorts' => $this->facts->sorts[] = $entry,
            'includes' => $this->facts->includes[] = $entry,
            'fields' => $this->facts->fields[] = $entry,
            default => null,
        };
    }

    /**
     * Peels `->default(<const>)` / `->nullable()` off the top of an entry expression, returning the base
     * expression plus the folded facts. An unrecognised or non-constant modifier just stops the peel —
     * the base still recovers, so an unfoldable tail never costs us a whole entry.
     *
     * @return array{0: Node\Expr, 1: bool, 2: string|int|float|bool|null, 3: bool}
     */
    private function peelModifiers(Node\Expr $expr, TypeScope $scope): array
    {
        $hasDefault = false;
        $default = null;
        $nullable = false;
        $base = $expr;

        while ($base instanceof Node\Expr\MethodCall && $base->name instanceof Node\Identifier) {
            $modifier = $base->name->toString();

            if ($modifier === 'nullable') {
                $nullable = true;
                $base = $base->var;

                continue;
            }

            if ($modifier === 'default') {
                $folded = $this->foldDefault($base, $scope);
                if ($folded !== null) {
                    [$hasDefault, $default] = [true, $folded[0]];
                }
                $base = $base->var;

                continue;
            }

            break;
        }

        return [$base, $hasDefault, $default, $nullable];
    }

    /**
     * A `->default(<value>)` argument folded to a scalar, or null when absent / non-constant.
     *
     * @return array{0: string|int|float|bool|null}|null
     */
    private function foldDefault(Node\Expr\MethodCall $node, TypeScope $scope): ?array
    {
        $arg = $node->getArgs()[0] ?? null;
        if ($arg === null) {
            return null;
        }

        $value = $scope->constantValueOf($arg->value);

        return $value !== null && $value->isScalar() ? [$value->scalar] : null;
    }

    private function entryFor(ConstValue $value, string $defaultKind): ?QbEntry
    {
        if ($value->isScalar() && is_string($value->scalar)) {
            return new QbEntry($value->scalar, $defaultKind);
        }

        if ($value->isDescriptor()) {
            $method = self::factoryMethod((string) $value->factory);
            $name = $this->descriptorName($value, $method);
            if ($name !== null) {
                return new QbEntry($name, $method, self::internalArg($value, $method));
            }
        }

        return null;
    }

    /**
     * The public filter name from the descriptor's first argument. `AllowedFilter::trashed()` may be
     * called with no name, in which case Spatie's documented default is `trashed`.
     */
    private function descriptorName(ConstValue $value, string $method): ?string
    {
        $first = $value->args[0] ?? null;
        if ($first instanceof ConstValue && $first->isScalar() && is_string($first->scalar) && $first->scalar !== '') {
            return $first->scalar;
        }

        return $method === 'trashed' ? 'trashed' : null;
    }

    /** The internal column name argument, when it's a non-empty string. Position varies by factory. */
    private static function internalArg(ConstValue $descriptor, string $method): ?string
    {
        $arg = $descriptor->args[self::INTERNAL_ARG_INDEX[$method] ?? 1] ?? null;

        return $arg instanceof ConstValue && $arg->isScalar() && is_string($arg->scalar) && $arg->scalar !== ''
            ? $arg->scalar
            : null;
    }

    /**
     * How a filter's value gets typed, per kind: `exact` and a static `operator` type off the internal
     * column, a `callback` off whatever column its closure's `where(…)` targets, and a `custom` records
     * its filter class for the extension to analyse. Anything else stays a plain string.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string|null} typeColumn, filterClass, factoryEnum, factoryClass
     */
    private function filterTyping(QbEntry $entry, ConstValue $value, Node\Expr $base, TypeScope $scope): array
    {
        return match ($entry->kind) {
            'exact' => [$entry->column(), null, null, null],
            'operator' => [$this->operatorIsStatic($value) ? $entry->column() : null, null, null, null],
            'callback' => [$this->callbackColumn($base), null, null, null],
            'custom' => [null, $this->customFilterClass($value, $base, $scope), null, null],
            default => $this->factoryTyping($value, $entry),
        };
    }

    /**
     * Typing for a filter built by a PROJECT factory (a `ListFilters::enum(...)` style helper returning
     * an `AllowedFilter`) rather than a Spatie `AllowedFilter::*` one. Everything needed is already in
     * the call-site arguments folded into the descriptor, so the factory body is never descended into: a
     * backed-enum class-string argument names the value domain, otherwise the filter's own name is the
     * column to type off, matching the usual `$column ?? $key` idiom. Bare strings and unhandled Spatie
     * kinds return all-null and stay plain strings.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string|null}
     */
    private function factoryTyping(ConstValue $value, QbEntry $entry): array
    {
        $factory = (string) $value->factory;
        if ($factory === '' || str_starts_with($factory, self::ALLOWED_FILTER.'::')) {
            return [null, null, null, null];
        }

        $factoryClass = self::factoryClass($factory);
        $enum = self::factoryEnumArg($value);

        return $enum !== null
            ? [null, null, $enum, $factoryClass]
            : [$entry->name, null, null, $factoryClass];
    }

    /** A backed-enum class-string among the folded arguments — the filter's value domain. */
    private static function factoryEnumArg(ConstValue $value): ?string
    {
        foreach ($value->args as $arg) {
            if ($arg->isScalar() && is_string($arg->scalar) && $arg->scalar !== ''
                && enum_exists($arg->scalar) && is_subclass_of($arg->scalar, BackedEnum::class)
            ) {
                return $arg->scalar;
            }
        }

        return null;
    }

    /** The declaring class of a `Class::method` factory FQCN. */
    private static function factoryClass(string $factory): string
    {
        $sep = strpos($factory, '::');

        return $sep === false ? $factory : substr($factory, 0, $sep);
    }

    /** Whether an `operator` descriptor's operator argument is an equality comparison. */
    private function operatorIsStatic(ConstValue $value): bool
    {
        $operator = $value->args[1] ?? null;

        return $operator instanceof ConstValue && $operator->isScalar() && is_string($operator->scalar)
            && in_array($operator->scalar, self::STATIC_OPERATORS, true);
    }

    /** The column a callback filter's inline closure filters on. */
    private function callbackColumn(Node\Expr $base): ?string
    {
        if (! $base instanceof Node\Expr\StaticCall) {
            return null;
        }

        $callback = $base->getArgs()[1]->value ?? null;

        return $callback instanceof Node\Expr\Closure || $callback instanceof Node\Expr\ArrowFunction
            ? $this->whereColumns->fromClosure($callback)
            : null;
    }

    /**
     * The folded `F::class` second argument, else the type of a `new F` argument. A variable or dynamic
     * instance is unrecoverable.
     */
    private function customFilterClass(ConstValue $value, Node\Expr $base, TypeScope $scope): ?string
    {
        $second = $value->args[1] ?? null;
        if ($second instanceof ConstValue && $second->isScalar() && is_string($second->scalar) && $second->scalar !== '') {
            return $second->scalar;
        }

        if ($base instanceof Node\Expr\StaticCall) {
            $argument = $base->getArgs()[1]->value ?? null;
            if ($argument instanceof Node\Expr\New_) {
                $type = $scope->typeOf($argument);
                if ($type instanceof ClassT) {
                    return $type->fqcn;
                }
            }
        }

        return null;
    }

    private function recordTerminal(Node\Expr\MethodCall $node, string $name, TypeScope $scope): void
    {
        // The engine walks the entry method fully before descending, so the first terminal recorded is
        // the outermost one — per-page comes from the shallowest call site.
        if ($this->facts->paginates) {
            return;
        }

        $this->facts->paginates = true;
        $this->facts->paginationKind = $this->terminals[$name];

        $args = $node->getArgs();
        if (isset($args[0])) {
            $value = $scope->constantValueOf($args[0]->value);
            if ($value !== null && $value->isScalar() && is_int($value->scalar)) {
                $this->facts->perPage = $value->scalar;
            }
        }
    }

    private function receiverIsBuilder(Node\Expr $receiver, TypeScope $scope): bool
    {
        $type = $scope->typeOf($receiver);

        return $type instanceof ClassT && is_a($type->fqcn, self::QUERY_BUILDER, true);
    }

    /**
     * The first sentence of a comment whose end line immediately precedes the entry — verbatim, no tag
     * parsing. Null when nothing attaches that closely.
     */
    private function leadingComment(Node $item): ?string
    {
        $comments = $item->getComments();
        if ($comments === []) {
            return null;
        }

        $comment = $comments[count($comments) - 1];
        if ($comment->getEndLine() !== $item->getStartLine() - 1) {
            return null;
        }

        $text = self::stripCommentMarkers($comment);

        return $text === '' ? null : self::firstSentence($text);
    }

    /** A comment body with its markers stripped, collapsed to a single line. */
    private static function stripCommentMarkers(Comment $comment): string
    {
        // Block delimiters first, then per-line leading markers (`//`, `#`, `*`).
        $text = preg_replace('~/\*\*?|\*/~', '', $comment->getText()) ?? $comment->getText();

        $lines = array_map(
            static fn (string $line): string => trim(preg_replace('~^\s*(//|#|\*)\s?~', '', $line) ?? $line),
            preg_split('/\R/', $text) ?: [$text],
        );

        $collapsed = preg_replace('/\s+/', ' ', implode(' ', $lines)) ?? '';

        return trim($collapsed);
    }

    /** Up to and including the first sentence-terminating period, else the whole line. */
    private static function firstSentence(string $text): string
    {
        if (preg_match('/^.*?[.!?](?=\s|$)/', $text, $matches) === 1) {
            return trim($matches[0]);
        }

        return $text;
    }

    /** The `method` segment of a `Class::method` factory FQCN. */
    private static function factoryMethod(string $factory): string
    {
        $sep = strpos($factory, '::');

        return $sep === false ? $factory : substr($factory, $sep + 2);
    }
}
