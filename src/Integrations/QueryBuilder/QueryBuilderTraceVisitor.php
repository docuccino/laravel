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
 * The Query-Builder integration's {@see TraceVisitor} — the productionised Spike B "Scramble-Pro-
 * beater". Pure semantics + harvesting through {@see TypeScope}; imports zero PHPStan. It recovers,
 * off any `Spatie\QueryBuilder\QueryBuilder` receiver at any chain depth (the engine descends into
 * app-code helpers, so the `ListQueryBuilder::for()` two-deep pattern works):
 *
 *   - the **subject model** off `QueryBuilder::for(Article::class)` (a `Model::class` const string) or
 *     `QueryBuilder::for(Article::query())` (the receiver expression's model type) — the model whose
 *     column casts type the exact filters;
 *   - `allowedFilters` / `allowedSorts` / `allowedIncludes` / `allowedFields` literals — strings and
 *     factory descriptors (`AllowedFilter::exact('status')`) folded at the AST level before PHPStan
 *     collapses them to a plain object type (the crux of Spike B), including the **internal column**
 *     (`AllowedFilter::exact('status', 'status_code')`), constant `->default(…)`/`->nullable()`
 *     **chained modifiers**, and a line or block **comment directly above** the entry;
 *   - `defaultSort`/`defaultSorts` documented defaults;
 *   - paginating terminals (`paginate`/`simplePaginate`/`cursorPaginate` plus any configured custom
 *     terminal, e.g. a `paginateList` helper) with the per-page folded from the OUTERMOST call site.
 *
 * Every un-foldable allow-list entry is recorded on {@see QueryBuilderFacts::$unresolved} with its
 * source location, so a dynamic chain degrades to a named diagnostic rather than silence.
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
     * The internal-column-name argument position per factory — the default is the second argument
     * (`AllowedFilter::exact('status', 'status_code')`), but `callback`/`custom` carry a
     * closure/instance there (internal name is third) and `operator` a `FilterOperator` + boolean
     * before it (internal name is fourth).
     *
     * @var array<string, int>
     */
    private const INTERNAL_ARG_INDEX = [
        'callback' => 2,
        'custom' => 2,
        'operator' => 3,
    ];

    /** `FilterOperator` cases that compare for equality — the value is typed off the column like `exact`. */
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

        // `QueryBuilder::for(Model::class)` — the chain's origin: recover the subject model.
        if ($node instanceof Node\Expr\StaticCall
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'for'
        ) {
            $this->recoverSubject($node, $scope);
        }

        // Descend into any app-code call so allow-lists built inside a helper are reached; the engine
        // declines vendor / magic / over-budget descent on its own (Spike B split).
        return $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall;
    }

    /**
     * Follow a callee whose return type IS a Spatie `QueryBuilder` (subclass) even when it lies
     * outside the configured project paths — the modular `$query->query(): InvoiceQueryBuilder` hop
     * where the whole `allowedFilters(...)` chain lives. The engine still never descends into vendor,
     * so this reaches the app's own Query class without following the package's builder methods.
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
     * Recover the subject model FQCN from a `QueryBuilder::for(…)` origin. The first `for()` reached
     * wins (there is one per chain); an unresolvable subject leaves {@see QueryBuilderFacts::$subjectModel}
     * null so every filter degrades to a plain string.
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
     * The model FQCN behind a `for(…)` argument: a `Model::class` const string, else the argument's
     * own model type (`for($query)` / `for(Model::query())` → a builder/relation carrying the model
     * as a generic arg, or the model itself).
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

    /**
     * Fold one recovered allow-list expression into an entry (peeling constant `->default()`/
     * `->nullable()` modifiers and attributing a leading comment), or record it unresolved.
     */
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

        // Only filters carry column typing / a custom-filter class; sorts/includes/fields never do.
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
     * Peel recognised constant-foldable chained modifiers off the top of an allow-list expression,
     * returning the base descriptor/scalar expression plus the folded modifier facts. Only
     * `->default(<const>)` and `->nullable()` are recognised; an unrecognised or non-constant modifier
     * stops the peel (the base still recovers — a recognised entry never degrades to unresolved noise
     * over an unfoldable tail).
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
     * Fold a `->default(<value>)` argument to a scalar, or null when it is absent / non-constant.
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
     * The public filter name from a descriptor's first argument, or — for `AllowedFilter::trashed()`
     * called with no name — its documented default `trashed`.
     */
    private function descriptorName(ConstValue $value, string $method): ?string
    {
        $first = $value->args[0] ?? null;
        if ($first instanceof ConstValue && $first->isScalar() && is_string($first->scalar) && $first->scalar !== '') {
            return $first->scalar;
        }

        return $method === 'trashed' ? 'trashed' : null;
    }

    /** The factory's internal-column-name argument (position varies by factory), when a non-empty string. */
    private static function internalArg(ConstValue $descriptor, string $method): ?string
    {
        $arg = $descriptor->args[self::INTERNAL_ARG_INDEX[$method] ?? 1] ?? null;

        return $arg instanceof ConstValue && $arg->isScalar() && is_string($arg->scalar) && $arg->scalar !== ''
            ? $arg->scalar
            : null;
    }

    /**
     * The column a filter types its value off (else null), and the custom-filter class FQCN (else
     * null), per kind: `exact` and a static `operator` type off the internal column; a `callback`
     * types off the column its closure's `where(…)` targets; a `custom` records its filter class for
     * the extension to analyse. Everything else stays a plain string.
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
     * A filter produced by a PROJECT factory — a helper method returning a Spatie `AllowedFilter`
     * (e.g. a `ListFilters::enum(...)` idiom), as opposed to a Spatie `AllowedFilter::*` factory. Its
     * typing comes entirely from the CALL-SITE arguments already folded into the descriptor — no descent
     * into the factory body is needed: a backed-enum class-string argument names the value domain (typed
     * off that enum directly), otherwise the filter's own name is the column to type off the model cast
     * (the `$column ?? $key` idiom). A bare string, or a Spatie-own factory kind not handled above,
     * returns all-null (unchanged — stays a plain string).
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

    /** A backed-enum class-string among the factory's folded arguments (its value domain), else null. */
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

    /** Whether an `AllowedFilter::operator` descriptor's operator argument is an equality comparison. */
    private function operatorIsStatic(ConstValue $value): bool
    {
        $operator = $value->args[1] ?? null;

        return $operator instanceof ConstValue && $operator->isScalar() && is_string($operator->scalar)
            && in_array($operator->scalar, self::STATIC_OPERATORS, true);
    }

    /** The column a callback filter's inline closure filters on, via {@see WhereColumnAnalyzer}. */
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
     * The custom-filter class FQCN: the folded `F::class` second argument, else the instantiated
     * class's type off a `new F` argument. A variable/dynamic instance is unrecoverable (null).
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
        // The outermost terminal is the first one recorded (the engine walks the entry method fully
        // before descending), so per-page comes from the shallowest call site (design §4).
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
     * The first sentence(s) of a line or block comment directly above an allow-list entry (its end
     * line immediately precedes the entry) — verbatim, no tag parsing. Returns null when no such
     * comment attaches.
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

    /** Strip line/block comment markers and collapse a comment body to a single line. */
    private static function stripCommentMarkers(Comment $comment): string
    {
        // Drop block open/close delimiters, then per-line leading markers (`//`, `#`, `*`).
        $text = preg_replace('~/\*\*?|\*/~', '', $comment->getText()) ?? $comment->getText();

        $lines = array_map(
            static fn (string $line): string => trim(preg_replace('~^\s*(//|#|\*)\s?~', '', $line) ?? $line),
            preg_split('/\R/', $text) ?: [$text],
        );

        $collapsed = preg_replace('/\s+/', ' ', implode(' ', $lines)) ?? '';

        return trim($collapsed);
    }

    /** The first sentence: up to and including the first sentence-terminating period, else the whole line. */
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
