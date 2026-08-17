<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\LocalWrites;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;
use ReflectionMethod;
use Throwable;

/**
 * Recovers the query key a paginated endpoint reads its PAGE SIZE from, by following the paginating
 * terminal's size argument back to a `$request->integer('per_page', …)` — through a local variable, and
 * into a helper on another class, which is where an application sharing one clamp across its list
 * endpoints puts it. A visitor drives it: {@see observe()} on every node, {@see terminal()} on every
 * paginating call, {@see recovered()} for the answer.
 *
 * **The size argument is the evidence, never the name.** Nothing here matches `per_page`, so an app whose
 * key is `limit` documents `limit`, and an app whose size is fixed at the call site
 * (`paginate(20)`, or the model's own `$perPage`) still documents nothing at all —
 * {@see PaginatorPageParameter} states that half of the rule in full.
 *
 * Three bounds, all stated because exceeding any of them recovers nothing rather than guessing:
 *
 * - **Value flow, never proximity.** {@see sourcesOf()} is the whole grammar: a value flows out of a read,
 *   a local, a clamp's arguments, an `(int)` cast, either side of `??`, a ternary's or `match`'s RESULT, or
 *   a callee's returned value — and out of nothing else. A key the code reads to make some other decision
 *   (a `match` subject, an `if` condition, a closure nobody calls) never reaches the size, so it is never
 *   the size. Anything this grammar cannot follow is refused; a size the reader cannot explain is a size
 *   it says nothing about.
 * - **One variable hop per body.** `$perPage = clamp(…); … paginate($perPage);` is the shape apps write; a
 *   longer chain within one body is dataflow guesswork, not a reading.
 * - **One callee deep, correlated by SOURCE RANGE.** A visitor is never told which call site the body it is
 *   walking belongs to, so the size argument names a callee, reflection says which lines that callee spans,
 *   and a `return` recorded inside them is that callee's. The file and the line have to come from the same
 *   source for that to mean anything — a trait's body is analysed as part of every using class, so its
 *   nodes carry the trait's lines, and the engine reports the trait's file with them. Two DIFFERENT keys
 *   reaching one size, or a variable written twice, recovers nothing: a wrong page-size key would send
 *   every generated client to a parameter the endpoint ignores, which is worse than sending them to none.
 *
 * @phpstan-type PageSizeSource array{read: RequestPageSizeKey|null, callee: string|null, file: string|null, var: string|null}
 * @phpstan-type SourceSpan array{file: string, start: int, end: int}
 */
final class RequestPageSizeReader
{
    /** A page size is only ever read off the request. */
    private const REQUEST = 'Illuminate\\Http\\Request';

    /**
     * Request accessors naming one query key in argument 0 with its fallback in argument 1. `integer()`
     * casts and the others do not, but the value-flow rule above is what proves a read IS the size, so
     * requiring the cast would only document the apps whose house style writes one.
     *
     * @var list<string>
     */
    private const ACCESSORS = ['integer', 'input', 'query', 'get', 'post'];

    /**
     * Terminals whose signature says WHERE the page size sits — Laravel's own three, all
     * `paginate($perPage, …)`. A custom terminal's own signature is unknown, so its arguments are never
     * read positionally; the vendor terminal it forwards to is reached by the trace anyway, and that one
     * is in here.
     *
     * @var list<string>
     */
    private const SIZE_TERMINALS = ['paginate', 'simplePaginate', 'cursorPaginate'];

    private const SIZE_POSITION = 0;

    private const SIZE_NAME = 'perPage';

    /** Clamp helpers written inline around a read. A clamp is not a constraint, so only the key travels. */
    private const CLAMPS = ['min', 'max', 'intval'];

    /**
     * Every `return` seen, with where it was written and where its value came from — the evidence that a
     * read inside a callee is the value that callee ANSWERS with.
     *
     * @var list<array{file: string, line: int, sources: list<PageSizeSource>}>
     */
    private array $returns = [];

    /**
     * Spans of the closures, functions and anonymous classes written inside a walked body. A `return` in
     * one of them is that inner body's, not the method whose lines enclose it.
     *
     * @var list<SourceSpan>
     */
    private array $nested = [];

    /**
     * Local assignments by `file|variable`, null once a second write retires one.
     *
     * @var array<string, list<PageSizeSource>|null>
     */
    private array $assignments = [];

    /**
     * Files where a write named no single local ({@see LocalWrites::retiresEveryLocal()}), so no variable
     * of that file can be followed at all.
     *
     * @var array<string, true>
     */
    private array $opaqueFiles = [];

    /**
     * Every page-size argument seen, from any terminal at any depth: an outer custom terminal hides the
     * vendor one's arguments from the FACTS, but both are walked, and only one of them will name a read.
     *
     * @var list<PageSizeSource>
     */
    private array $sizes = [];

    /** @var list<string> */
    private array $dependencyFiles = [];

    private bool $dirty = false;

    private ?RequestPageSizeKey $resolved = null;

    /**
     * Records what a body returns and what its locals hold. Safe to call on every node of every walked
     * body.
     */
    public function observe(Node $node, TypeScope $scope): void
    {
        if ($node instanceof Node\Stmt\Return_ && $node->expr !== null) {
            $location = $scope->location($node);
            $this->returns[] = [
                'file' => $location->file,
                'line' => $location->line ?? 0,
                'sources' => $this->sourcesOf($node->expr, $scope),
            ];
            $this->dirty = true;
        }

        // Every declaration that can nest INSIDE a method body and return a value of its own. A named class
        // cannot, and treating one as nested would retire every method it holds.
        if ($node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
            || $node instanceof Node\Stmt\Function_
            || ($node instanceof Node\Stmt\Class_ && $node->name === null)
        ) {
            $this->nested[] = [
                'file' => $scope->location($node)->file,
                'start' => $node->getStartLine(),
                'end' => $node->getEndLine(),
            ];
            $this->dirty = true;
        }

        $this->observeWrites($node, $scope);
    }

    /**
     * Records the page-size argument of a terminal whose signature names one. An argument that was never
     * written is the model's own `$perPage`, which reads no request key.
     */
    public function terminal(Node\Expr\MethodCall|Node\Expr\StaticCall $call, string $terminal, TypeScope $scope): void
    {
        if (! in_array($terminal, self::SIZE_TERMINALS, true) || $call->isFirstClassCallable()) {
            return;
        }

        $argument = null;
        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->unpack) {
                return; // a spread breaks positional binding, so nothing here names the size
            }
            $named = $arg->name?->toString();
            if ($named === self::SIZE_NAME || ($named === null && $index === self::SIZE_POSITION)) {
                $argument = $arg->value;
            }
        }

        if ($argument === null) {
            return;
        }

        $this->sizes = [...$this->sizes, ...$this->sourcesOf($argument, $scope)];
        $this->dirty = true;
    }

    /**
     * The one page-size read every recorded size argument agrees on, or null when they name none or
     * several. Resolved on demand — a read written inside a callee is only seen once the engine has
     * descended into it, which happens after the call site was walked — and memoised until the next
     * observation, so a visitor may ask after every node.
     */
    public function recovered(): ?RequestPageSizeKey
    {
        if (! $this->dirty) {
            return $this->resolved;
        }
        $this->dirty = false;

        $found = [];
        foreach ($this->sizes as $source) {
            foreach ($this->resolve($source, 0, 0) as $read) {
                // Keyed by name, so agreeing reads collapse however the walk ordered them. Two reads of
                // one key that DISAGREE on the fallback settle on no default rather than on whichever
                // arrived last — a default that depended on encounter order would not be a fact.
                $found[$read->key] = array_key_exists($read->key, $found) && $found[$read->key]->default !== $read->default
                    ? new RequestPageSizeKey($read->key)
                    : $read;
            }
        }

        return $this->resolved = count($found) === 1 ? reset($found) : null;
    }

    /**
     * The files a recovered fact was WRITTEN in — the helper's own, its parents' and its traits' — for
     * {@see RouteContext::recordDependencyFiles()}. The trace reports
     * every file it descended into, but a fact reached through reflection owes its own accounting.
     *
     * Resolves first, so the list never depends on whether the caller asked for the key before the files.
     *
     * @return list<string>
     */
    public function dependencyFiles(): array
    {
        $this->recovered();

        return array_values(array_unique($this->dependencyFiles));
    }

    /** The local-write half of {@see observe()} — one grammar, read from {@see LocalWrites}. */
    private function observeWrites(Node $node, TypeScope $scope): void
    {
        $file = null;

        $assignment = LocalWrites::assignment($node);
        if ($assignment !== null) {
            [$name, $expr] = $assignment;
            $file = $scope->location($node)->file;
            $key = $file.'|'.$name;
            // A variable written twice names no single origin, so the second write retires it.
            $this->assignments[$key] = array_key_exists($key, $this->assignments)
                ? null
                : $this->sourcesOf($expr, $scope);
        }

        foreach (LocalWrites::retires($node) as $name) {
            $file ??= $scope->location($node)->file;
            $this->assignments[$file.'|'.$name] = null;
        }

        if (LocalWrites::retiresEveryLocal($node)) {
            $file ??= $scope->location($node)->file;
            $this->opaqueFiles[$file] = true;
        }

        if ($file !== null) {
            $this->dirty = true;
        }
    }

    /**
     * @param  PageSizeSource  $source
     * @param  int  $varHops  variable hops spent in the body this source was written in
     * @param  int  $calleeHops  bodies descended into, from the call site
     * @return list<RequestPageSizeKey>
     */
    private function resolve(array $source, int $varHops, int $calleeHops): array
    {
        if ($source['read'] !== null) {
            return [$source['read']];
        }

        if ($source['var'] !== null && $source['file'] !== null) {
            if ($varHops > 0 || isset($this->opaqueFiles[$source['file']])) {
                // One variable hop (see the class docblock) — and none at all in a body where some write
                // landed on a local nothing names, since any of them could have been this one.
                return [];
            }

            $next = $this->assignments[$source['file'].'|'.$source['var']] ?? null;
            if ($next === null) {
                return [];
            }

            $found = [];
            foreach ($next as $inner) {
                $found = [...$found, ...$this->resolve($inner, $varHops + 1, $calleeHops)];
            }

            return $found;
        }

        if ($source['callee'] === null || $calleeHops > 0) {
            return [];
        }

        return $this->returnedBy($source['callee'], $calleeHops + 1);
    }

    /**
     * Where a value came from, as far as one expression can say: a read here and now, a local variable to
     * look up once the walk has seen its assignment, or the callee whose returns to look at. A list
     * because a clamp, a `??` or a `match` each pass a value through from several places at once.
     *
     * Every arm below is a form the VALUE flows through. An expression this grammar does not name is
     * refused rather than guessed at, and the parts of these forms that are read to DECIDE something — a
     * ternary's condition, a `match` subject and its conditions — are not sources of the value they pick.
     *
     * @return list<PageSizeSource>
     */
    private function sourcesOf(Node\Expr $expr, TypeScope $scope): array
    {
        if ($expr instanceof Node\Expr\MethodCall) {
            $read = $this->readAt($expr, $scope);
            if ($read !== null) {
                return [['read' => $read, 'callee' => null, 'file' => null, 'var' => null]];
            }
        }

        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return [['read' => null, 'callee' => null, 'file' => $scope->location($expr)->file, 'var' => $expr->name]];
        }

        // An int cast passes the value straight through, which is how an app that reads with `input()`
        // still hands `paginate()` an integer.
        if ($expr instanceof Node\Expr\Cast\Int_) {
            return $this->sourcesOf($expr->expr, $scope);
        }

        if ($expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && in_array(strtolower($expr->name->toString()), self::CLAMPS, true)
        ) {
            return $this->sourcesOfAll(array_values(array_map(
                static fn (Node\Arg $arg): Node\Expr => $arg->value,
                $expr->getArgs(),
            )), $scope);
        }

        if ($expr instanceof Node\Expr\BinaryOp\Coalesce) {
            return $this->sourcesOfAll([$expr->left, $expr->right], $scope);
        }

        // `$c ? $a : $b` takes its value from the arms; `$a ?: $b` from the condition and the arm.
        if ($expr instanceof Node\Expr\Ternary) {
            return $this->sourcesOfAll([$expr->if ?? $expr->cond, $expr->else], $scope);
        }

        if ($expr instanceof Node\Expr\Match_) {
            return $this->sourcesOfAll(
                array_values(array_map(static fn (Node\MatchArm $arm): Node\Expr => $arm->body, $expr->arms)),
                $scope,
            );
        }

        $callee = $this->calleeOf($expr, $scope);

        return $callee === null ? [] : [['read' => null, 'callee' => $callee, 'file' => null, 'var' => null]];
    }

    /**
     * @param  list<Node\Expr>  $exprs
     * @return list<PageSizeSource>
     */
    private function sourcesOfAll(array $exprs, TypeScope $scope): array
    {
        $sources = [];
        foreach ($exprs as $expr) {
            $sources = [...$sources, ...$this->sourcesOf($expr, $scope)];
        }

        return $sources;
    }

    /**
     * The reads that reach what one callee RETURNS. Reflection answers which source lines that callee
     * spans, which is the only correlation available: the trace hands a visitor another file's nodes
     * without ever saying which call led there.
     *
     * @return list<RequestPageSizeKey>
     */
    private function returnedBy(string $callee, int $calleeHops): array
    {
        $span = $this->spanOf($callee);
        if ($span === null) {
            return [];
        }

        $found = [];
        foreach ($this->returns as $return) {
            if (! $this->encloses($span, $return['file'], $return['line'])) {
                continue;
            }
            foreach ($return['sources'] as $source) {
                $found = [...$found, ...$this->resolve($source, 0, $calleeHops)];
            }
        }

        return $found;
    }

    /**
     * A callee's own source lines, and the files its declaration spans for the fragment cache. Null when
     * nothing can be reflected: an absent parent makes the autoloader throw an `Error` rather than a
     * `ReflectionException`, and a route that publishes nothing at all would be a worse answer than one
     * page-size key fewer.
     *
     * @return SourceSpan|null
     */
    private function spanOf(string $callee): ?array
    {
        $split = explode('::', $callee, 2);
        if (count($split) !== 2) {
            return null;
        }

        try {
            if (! method_exists($split[0], $split[1])) {
                return null;
            }

            $reflection = new ReflectionMethod($split[0], $split[1]);
            $file = $reflection->getFileName();
            $start = $reflection->getStartLine();
            $end = $reflection->getEndLine();

            $declaration = DeclarationFiles::of($split[0]);
        } catch (Throwable) {
            return null;
        }

        if ($file === false || $start === false || $end === false) {
            return null; // an internal or evaluated method has no lines to correlate against
        }

        $this->dependencyFiles = [...$this->dependencyFiles, ...$declaration];

        return ['file' => $file, 'start' => $start, 'end' => $end];
    }

    /**
     * Whether a file+line pair sits in a span — and in the span's OWN body, not in a closure or a nested
     * declaration the span happens to enclose.
     *
     * @param  SourceSpan  $span
     */
    private function encloses(array $span, string $file, int $line): bool
    {
        if ($line < $span['start'] || $line > $span['end'] || ! self::samePath($file, $span['file'])) {
            return false;
        }

        foreach ($this->nested as $inner) {
            if ($line >= $inner['start'] && $line <= $inner['end'] && self::samePath($file, $inner['file'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * A `$request-><accessor>('key', <default>)` read, when the receiver really is a request — the type
     * decides, never the variable's name.
     */
    private function readAt(Node\Expr\MethodCall $call, TypeScope $scope): ?RequestPageSizeKey
    {
        if (! $call->name instanceof Node\Identifier
            || ! in_array($call->name->toString(), self::ACCESSORS, true)
            || $call->isFirstClassCallable()
        ) {
            return null;
        }

        $args = $call->getArgs();
        // `query()` with no key returns the whole bag and names nothing.
        $key = isset($args[0]) ? $scope->constantValueOf($args[0]->value) : null;
        if ($key === null || ! $key->isScalar() || ! is_string($key->scalar) || $key->scalar === '') {
            return null;
        }

        if (! $this->receiverIsRequest($call->var, $scope)) {
            return null;
        }

        $fallback = isset($args[1]) ? $scope->constantValueOf($args[1]->value) : null;
        $default = $fallback !== null && $fallback->isScalar() && is_int($fallback->scalar) ? $fallback->scalar : null;

        return new RequestPageSizeKey($key->scalar, $default);
    }

    private function receiverIsRequest(Node\Expr $receiver, TypeScope $scope): bool
    {
        $type = $scope->typeOf($receiver);

        return $type instanceof ClassT && is_a($type->fqcn, self::REQUEST, true);
    }

    /** The `Class::method` a call dispatches to, as far as the scope can name it. */
    private function calleeOf(Node\Expr $expr, TypeScope $scope): ?string
    {
        if ($expr instanceof Node\Expr\StaticCall) {
            // A static call folds to a descriptor carrying its resolved `Class::method`.
            $value = $scope->constantValueOf($expr);
            $factory = $value !== null && $value->isDescriptor() ? (string) $value->factory : '';

            return str_contains($factory, '::') ? $factory : null;
        }

        if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            $type = $scope->typeOf($expr->var);

            return $type instanceof ClassT ? $type->fqcn.'::'.$expr->name->toString() : null;
        }

        return null;
    }

    /** Two spellings of one file — the engine reports absolute paths, reflection resolves symlinks. */
    private static function samePath(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        return (realpath($left) ?: $left) === (realpath($right) ?: $right);
    }
}
