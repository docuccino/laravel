<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use BackedEnum;
use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\FoldsCallReturns;
use Docuccino\Core\Inference\FollowsReturnType;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Core\Provenance\SourcePathResolver;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use Docuccino\Laravel\Integrations\Support\FoldedArguments;
use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;
use Docuccino\Laravel\Integrations\Support\RequestPageSizeReader;
use PhpParser\Comment;
use PhpParser\Node;

/**
 * Harvests a `Spatie\QueryBuilder\QueryBuilder` chain into {@see QueryBuilderFacts}. Pure semantics
 * over {@see TypeScope} — imports zero PHPStan. Works off a builder receiver at any chain depth, since
 * the engine descends into app-code helpers (so `ListQueryBuilder::for()` two levels deep still works).
 *
 * It recovers the subject model from `for(Article::class)` / `for(Article::query())`, or from the
 * `parent::__construct(Article::query())` a subclass that IS the builder writes instead — that model's
 * column casts are what type the exact filters — plus the `allowed*` lists, `defaultSort(s)`, and the
 * paginating terminal (per-page folded from the OUTERMOST call site).
 *
 * One visitor may be driven over several trace roots (the action, then each injected builder's
 * constructor), which is why the first subject/terminal reached wins and an allow-list call site is
 * harvested at most once.
 *
 * Allow-list entries are folded at the AST level, which is the whole point: by the time PHPStan is done
 * with `AllowedFilter::exact('status')` it's a plain object type with the arguments gone. Folding here
 * keeps the internal column (`exact('status', 'status_code')`), constant `->default()`/`->nullable()`
 * modifiers, and a comment sitting directly above the entry.
 *
 * An entry whose public name is NOT written at the call site — `$this->termFilter()`,
 * `ListFilters::status()`, `->allowedFilters(...$this->allowedFilters())` — only its callee's body can
 * answer for, so the entry is queued through {@see FoldsCallReturns} and a placeholder holds its position
 * until the engine folds the return. Anything still un-foldable lands on
 * {@see QueryBuilderFacts::$unresolved} with its source location, so a dynamic chain becomes a named
 * diagnostic rather than a silent omission.
 */
final class QueryBuilderTraceVisitor implements FollowsReturnType, TraceVisitor
{
    private const QUERY_BUILDER = 'Spatie\\QueryBuilder\\QueryBuilder';

    private const ALLOWED_FILTER = 'Spatie\\QueryBuilder\\AllowedFilter';

    /**
     * Return types worth following out of the configured project paths: the builder itself (the
     * `$query->query()` hop) and the allow-list entry classes a filter factory answers with.
     *
     * @var list<string>
     */
    private const FOLLOWED_RETURNS = [
        self::QUERY_BUILDER,
        self::ALLOWED_FILTER,
        'Spatie\\QueryBuilder\\AllowedSort',
        'Spatie\\QueryBuilder\\AllowedInclude',
    ];

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
     * Entries waiting on a return fold, by the placeholder token holding each one's position. A NUL prefix
     * keeps a token out of the space of real filter names.
     *
     * @var array<string, QbEntrySlot>
     */
    private array $slots = [];

    private int $slotSeq = 0;

    /** @var array<string, true> allow-list call sites already harvested — see {@see isFirstHarvestOf()} */
    private array $harvested = [];

    /** @var array<string, true> call sites already diagnosed — see {@see recordUnresolved()} */
    private array $diagnosed = [];

    /**
     * @param  list<string>  $customTerminals  extra paginating terminals (length-aware), e.g. `paginateList`
     * @param  ?SourcePathResolver  $paths  relativises a diagnostic's file; without one it degrades to the
     *                                      basename, never an absolute path
     */
    public function __construct(
        public readonly QueryBuilderFacts $facts = new QueryBuilderFacts,
        array $customTerminals = [],
        private readonly WhereColumnAnalyzer $whereColumns = new WhereColumnAnalyzer,
        private readonly ?SourcePathResolver $paths = null,
        private readonly RequestPageSizeReader $pageSize = new RequestPageSizeReader,
    ) {
        $terminals = PaginationTerminalVisitor::PAGINATOR_TERMINALS;
        foreach ($customTerminals as $terminal) {
            $terminals[$terminal] ??= 'length';
        }
        $this->terminals = $terminals;
    }

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        $this->pageSize->observe($node, $scope);

        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $this->visitMethodCall($node, $node->name->toString(), $scope);
        }

        if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
            // `for(…)` is the chain's origin, and `parent::__construct(…)` is that same origin written by a
            // subclass which IS the builder — the subject model comes from one or the other.
            $called = $node->name->toString();
            if ($called === 'for') {
                $this->recoverSubject($node, $scope);
            } elseif ($called === '__construct') {
                $this->recoverSubjectFromParent($node, $scope);
            }
        }

        // Resolved after every node, not once at the end: a size key written inside a helper only shows up
        // once the engine has descended into it, which is after the call site was walked.
        $this->facts->pageSize = $this->pageSize->recovered();

        // Descend into any app-code call so allow-lists built inside a helper are reached; the engine
        // declines vendor / magic / over-budget descent on its own.
        return $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall;
    }

    /**
     * Files the page-size recovery consulted by reflection, which the trace's own file set does not cover.
     *
     * @return list<string>
     */
    public function dependencyFiles(): array
    {
        return $this->pageSize->dependencyFiles();
    }

    /**
     * Follows a callee returning a `QueryBuilder` subclass even outside the configured project paths —
     * that's the `$query->query(): InvoiceQueryBuilder` hop where the whole chain often lives — or one
     * returning an allow-list entry, which is a modular app's filter factory. Vendor is still never
     * descended into, so this reaches the app's Query class, not the package's own methods.
     */
    public function followsReturnType(DType $returnType): bool
    {
        if (! $returnType instanceof ClassT) {
            return false;
        }

        foreach (self::FOLLOWED_RETURNS as $fqcn) {
            if (is_a($returnType->fqcn, $fqcn, true)) {
                return true;
            }
        }

        return false;
    }

    private function visitMethodCall(Node\Expr\MethodCall $node, string $name, TypeScope $scope): void
    {
        if (! $this->receiverIsBuilder($node->var, $scope)) {
            return;
        }

        if (isset(self::CONFIG_METHODS[$name]) && $this->isFirstHarvestOf($node, $name, $scope)) {
            [$bucket, $defaultKind] = self::CONFIG_METHODS[$name];
            $this->harvest($node, $scope, $bucket, $defaultKind, $name);
        }

        if (isset($this->terminals[$name])) {
            // Every terminal at every depth, unlike the facts below: a custom terminal hides the vendor
            // one it forwards to, and the page size is an argument of the vendor one.
            $this->pageSize->terminal($node, $name, $scope);
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
     * The subject behind a `parent::__construct(Model::query()->…)` origin: a builder subclass that
     * configures itself in its own constructor never writes a `for()`. The package's constructor takes a
     * builder or a relation — never a class-string — so only the argument's TYPE answers here.
     */
    private function recoverSubjectFromParent(Node\Expr\StaticCall $node, TypeScope $scope): void
    {
        if ($this->facts->subjectModel !== null
            || $node->isFirstClassCallable()
            || ! $node->class instanceof Node\Name
            || strtolower($node->class->toString()) !== 'parent'
        ) {
            return;
        }

        $arg = $node->getArgs()[0] ?? null;
        if ($arg === null) {
            return;
        }

        $this->facts->subjectModel = self::modelFromType($scope->typeOf($arg->value));
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

        return self::modelFromType($scope->typeOf($arg));
    }

    /** The model an argument type names: itself when it is one, else the first model among its generic args. */
    private static function modelFromType(DType $type): ?string
    {
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

    /**
     * True the first time this exact call site is harvested. One visitor can be driven over more than one
     * trace root (the action, then each injected builder's constructor) and each root walks with its own
     * memo, so a configure helper both roots reach would otherwise be harvested twice — duplicating every
     * entry it registers, and every diagnostic it fails to.
     */
    private function isFirstHarvestOf(Node\Expr\MethodCall $node, string $method, TypeScope $scope): bool
    {
        $key = self::siteKey($scope->location($node), $method);
        if (isset($this->harvested[$key])) {
            return false;
        }
        $this->harvested[$key] = true;

        return true;
    }

    /** One written call site, byte-precise: two entries on the same line are still two sites. */
    private static function siteKey(SourceLocation $location, string $method): string
    {
        return $location->file.':'.$location->pos.':'.$location->line.':'.$method;
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

    /** Folds one allow-list expression into an entry, defers it to the engine, or records it unresolved. */
    private function collect(Node\Expr $expr, ?Node $itemNode, string $bucket, string $defaultKind, string $method, TypeScope $scope): void
    {
        [$base, $modifiers] = $this->peelModifiers($expr, $scope);
        $slot = new QbEntrySlot($bucket, $defaultKind, $method, $itemNode, $scope->location($expr), $modifiers);

        $value = $scope->constantValueOf($base);
        $entry = $value === null ? null : $this->entryFor($value, $defaultKind);
        if ($value === null || $entry === null) {
            // Nothing names this entry at the call site — the callee's body might, which only the engine
            // can answer for, and only once the walk is over.
            if (! $this->defer($base, $slot, $scope)) {
                $this->recordUnresolved($slot);
            }

            return;
        }

        $this->record($bucket, $this->entryWithContext($entry, $value, $slot, $base, $scope, $itemNode));
    }

    /**
     * Queue an entry behind a call only its callee's body can name, holding its position in the allow-list
     * with a placeholder so a folded entry lands where it was written. False means the engine won't answer.
     */
    private function defer(Node\Expr $base, QbEntrySlot $slot, TypeScope $scope): bool
    {
        if (! $scope instanceof FoldsCallReturns) {
            return false;
        }

        // Slot and placeholder go in BEFORE the request: the contract only promises the answer arrives
        // before the trace returns, so an engine that answers synchronously must already find them —
        // otherwise its answer is dropped and the placeholder is documented as a filter name.
        $token = "\0qb-pending-".$this->slotSeq++;
        $this->slots[$token] = $slot;
        $this->record($slot->bucket, new QbEntry($token, 'pending'));

        $queued = $scope->deferReturnFold($base, function (?ConstValue $value, ?Node\Expr $returned) use ($token): void {
            $this->resolveSlot($token, $value, $returned);
        });
        if ($queued) {
            return true;
        }

        // Nothing queued, so nothing will ever fill the slot: take it back out and let the caller degrade.
        unset($this->slots[$token]);
        $this->replaceSlot($slot->bucket, $token, []);

        return false;
    }

    /**
     * The engine's answer for one queued entry: it takes the placeholder's place — as several entries when
     * the callee answered with an ARRAY of them — or the placeholder goes and the entry is unresolved.
     */
    private function resolveSlot(string $token, ?ConstValue $value, ?Node\Expr $returned): void
    {
        $slot = $this->slots[$token] ?? null;
        if ($slot === null) {
            return;
        }
        unset($this->slots[$token]);

        $entries = [];
        try {
            if ($value === null) {
                $this->recordUnresolved($slot);
            } else {
                $entries = $this->foldedEntries($slot, $value, $returned);
            }
        } finally {
            // The placeholder never survives this call, whatever happens above — it would otherwise
            // document a filter named after an internal token.
            $this->replaceSlot($slot->bucket, $token, $entries);
        }
    }

    /**
     * What a folded return value contributes: one entry, or one per item of an array a helper answered with
     * (`->allowedFilters(...$this->allowedFilters())`). Items fold in source order, so the returned array
     * literal's items line up with them — which is how an entry in there keeps its own leading comment.
     *
     * @return list<QbEntry>
     */
    private function foldedEntries(QbEntrySlot $slot, ConstValue $value, ?Node\Expr $returned): array
    {
        if (! $value->isArray()) {
            $entry = $this->foldedEntry($slot, $value, $returned, $slot->itemNode);
            if ($entry === null) {
                $this->recordUnresolved($slot);
            }

            return $entry === null ? [] : [$entry];
        }

        $items = $returned instanceof Node\Expr\Array_ ? $returned->items : [];

        $entries = [];
        foreach ($value->items as $index => $item) {
            $itemNode = $items[$index] ?? null;
            $entry = $this->foldedEntry($slot, $item, $itemNode?->value, $itemNode);
            if ($entry === null) {
                $this->recordUnresolved($slot);

                continue;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /** One folded value as an entry, with the modifiers its own chain carries merged under the call site's. */
    private function foldedEntry(QbEntrySlot $slot, ConstValue $value, ?Node\Expr $valueNode, ?Node $itemNode): ?QbEntry
    {
        $entry = $this->entryFor($value, $slot->defaultKind);

        return $entry === null
            ? null
            : $this->entryWithContext($entry, $value, $slot, $valueNode, null, $itemNode, self::chainModifiers($value));
    }

    /**
     * The entry plus what only its surroundings can say: modifiers, filter typing, and a comment sitting
     * directly above it. `$base` is the expression the value folded from and `$scope` types it — both come
     * from the call site, except after a return fold, where `$base` belongs to the CALLEE's file and may
     * only be read as AST, so `$scope` is null and the scope-typed recoveries stand down.
     */
    private function entryWithContext(
        QbEntry $entry,
        ConstValue $value,
        QbEntrySlot $slot,
        ?Node\Expr $base,
        ?TypeScope $scope,
        ?Node $itemNode,
        ?QbModifiers $inner = null,
    ): QbEntry {
        // Only filters carry column typing or a custom-filter class; sorts/includes/fields never do.
        [$typeColumn, $filterClass, $factoryEnum, $factoryClass] = $slot->bucket === 'filters'
            ? $this->filterTyping($entry, $value, $base, $scope)
            : [null, null, null, null];

        $modifiers = $inner === null ? $slot->modifiers : $slot->modifiers->merge($inner);

        return new QbEntry(
            $entry->name,
            $entry->kind,
            $entry->internal,
            $modifiers->hasDefault,
            $modifiers->default,
            $modifiers->nullable,
            $itemNode !== null ? $this->leadingComment($itemNode) : null,
            typeColumn: $typeColumn,
            filterClass: $filterClass,
            factoryEnum: $factoryEnum,
            factoryClass: $factoryClass,
        );
    }

    /**
     * `->nullable()` / `->default(<const>)` a folded descriptor carries in its chain: the modifiers the
     * helper applied in its own body, where the call site has no AST to peel.
     */
    private static function chainModifiers(ConstValue $value): QbModifiers
    {
        $modifiers = new QbModifiers;
        foreach ($value->chain as $call) {
            if ($call['method'] === 'nullable') {
                $modifiers = $modifiers->merge(new QbModifiers(nullable: true));

                continue;
            }

            $first = $call['args'][0] ?? null;
            if ($call['method'] === 'default' && $first !== null && $first->isScalar()) {
                $modifiers = $modifiers->merge(new QbModifiers(true, $first->scalar));
            }
        }

        return $modifiers;
    }

    private function record(string $bucket, QbEntry $entry): void
    {
        match ($bucket) {
            'filters' => $this->facts->filters[] = $entry,
            'sorts' => $this->facts->sorts[] = $entry,
            'includes' => $this->facts->includes[] = $entry,
            'fields' => $this->facts->fields[] = $entry,
            'defaultSorts' => $this->facts->defaultSorts[] = $entry->name,
            default => null,
        };
    }

    /**
     * Swap a reserved placeholder for what the fold recovered — for nothing at all when it recovered
     * nothing, so an unanswered entry costs a diagnostic, never a fabricated parameter.
     *
     * @param  list<QbEntry>  $entries
     */
    private function replaceSlot(string $bucket, string $token, array $entries): void
    {
        if ($bucket === 'defaultSorts') {
            $this->facts->defaultSorts = self::spliced(
                $this->facts->defaultSorts,
                $token,
                array_map(static fn (QbEntry $entry): string => $entry->name, $entries),
            );

            return;
        }

        match ($bucket) {
            'filters' => $this->facts->filters = self::spliced($this->facts->filters, $token, $entries),
            'sorts' => $this->facts->sorts = self::spliced($this->facts->sorts, $token, $entries),
            'includes' => $this->facts->includes = self::spliced($this->facts->includes, $token, $entries),
            'fields' => $this->facts->fields = self::spliced($this->facts->fields, $token, $entries),
            default => null,
        };
    }

    /**
     * The list with the token-named placeholder replaced by `$replacement`.
     *
     * @template TItem of QbEntry|string
     *
     * @param  list<TItem>  $list
     * @param  list<TItem>  $replacement
     * @return list<TItem>
     */
    private static function spliced(array $list, string $token, array $replacement): array
    {
        foreach ($list as $index => $item) {
            if (($item instanceof QbEntry ? $item->name : $item) !== $token) {
                continue;
            }

            return [...array_slice($list, 0, $index), ...$replacement, ...array_slice($list, $index + 1)];
        }

        return $list;
    }

    /**
     * One note per unresolvable call site — a helper answering with a ten-item array is one thing the trace
     * could not resolve, not ten. The path is project-relative like every other emitted location: these
     * notes become diagnostics inside the cached fragment, so an absolute one would make the cache
     * host-specific.
     */
    private function recordUnresolved(QbEntrySlot $slot): void
    {
        $key = self::siteKey($slot->location, $slot->method);
        if (isset($this->diagnosed[$key])) {
            return;
        }
        $this->diagnosed[$key] = true;

        $file = $this->paths === null
            ? basename($slot->location->file)
            : $this->paths->relative($slot->location->file);

        $this->facts->unresolved[] = sprintf('%s entry at %s:%d', $slot->method, $file, $slot->location->line);
    }

    /**
     * Peels `->default(<const>)` / `->nullable()` off the top of an entry expression, returning the base
     * expression plus the folded facts. An unrecognised or non-constant modifier just stops the peel —
     * the base still recovers, so an unfoldable tail never costs us a whole entry.
     *
     * A first-class-callable modifier (`->default(...)`) stops it too: the entry is then a closure, not a
     * configured filter, so it stays unfolded and lands on {@see QueryBuilderFacts::$unresolved} rather
     * than being peeled into a filter the code never registers.
     *
     * @return array{0: Node\Expr, 1: QbModifiers}
     */
    private function peelModifiers(Node\Expr $expr, TypeScope $scope): array
    {
        $modifiers = new QbModifiers;
        $base = $expr;

        while ($base instanceof Node\Expr\MethodCall
            && $base->name instanceof Node\Identifier
            && ! $base->isFirstClassCallable()
        ) {
            $modifier = $base->name->toString();

            if ($modifier === 'nullable') {
                $modifiers = $modifiers->merge(new QbModifiers(nullable: true));
                $base = $base->var;

                continue;
            }

            if ($modifier === 'default') {
                $folded = $this->foldDefault($base, $scope);
                if ($folded !== null) {
                    $modifiers = $modifiers->merge(new QbModifiers(true, $folded[0]));
                }
                $base = $base->var;

                continue;
            }

            break;
        }

        return [$base, $modifiers];
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
     * called with no name, in which case Spatie's documented default is `trashed` — true of a call that
     * passed NO name, and of no other. A name written but unreadable is a filter the endpoint accepts
     * under some other key, and publishing `trashed` for it names a query parameter that does not exist;
     * null instead leaves the entry unresolved, which is what the caller diagnoses.
     */
    private function descriptorName(ConstValue $value, string $method): ?string
    {
        $first = $value->args[0] ?? null;
        if ($first !== null && $first->isScalar() && is_string($first->scalar) && $first->scalar !== '') {
            return $first->scalar;
        }

        return $method === 'trashed' && $first === null ? 'trashed' : null;
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
    private function filterTyping(QbEntry $entry, ConstValue $value, ?Node\Expr $base, ?TypeScope $scope): array
    {
        return match ($entry->kind) {
            'exact' => [$entry->column(), null, null, null],
            'operator' => [$this->operatorIsStatic($value) ? $entry->column() : null, null, null, null],
            'callback' => [$base === null ? null : $this->callbackColumn($base), null, null, null],
            'custom' => [null, $this->customFilterClass($value, $base, $scope), null, null],
            default => $this->factoryTyping($value, $entry),
        };
    }

    /**
     * Typing for a filter built by a PROJECT factory (a `ListFilters::enum(...)` style helper returning
     * an `AllowedFilter`) rather than a Spatie `AllowedFilter::*` one. Everything needed is already in
     * the call-site arguments folded into the descriptor, so the factory body is never descended into: a
     * backed-enum class-string argument names the value domain, otherwise a written column argument —
     * else the filter's own name — is the column to type off, the usual `$column ?? $key` idiom. A
     * second argument that isn't a column costs nothing: cast resolution fails closed to a plain
     * string. Bare strings and unhandled Spatie kinds return all-null and stay plain strings.
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
            : [$entry->column(), null, null, $factoryClass];
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
     * instance is unrecoverable, and so is the `new F` form once the expression came back from a return fold
     * — typing another file's node against this scope is not something the scope can honestly answer.
     */
    private function customFilterClass(ConstValue $value, ?Node\Expr $base, ?TypeScope $scope): ?string
    {
        $second = $value->args[1] ?? null;
        if ($second instanceof ConstValue && $second->isScalar() && is_string($second->scalar) && $second->scalar !== '') {
            return $second->scalar;
        }

        if ($base instanceof Node\Expr\StaticCall && $scope !== null) {
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
        // the outermost one — the shallowest call site is the one whose arguments reach the request.
        if ($this->facts->paginates) {
            return;
        }

        $this->facts->paginates = true;
        $this->facts->paginationKind = $this->terminals[$name];
        $this->facts->paginationTerminal = $name;

        $this->facts->paginationArgs = FoldedArguments::of($node, $scope);
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
