<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionMethod;

/**
 * Resolves the wrap key spatie nests a response payload under — `{ "data": <payload> }` by default.
 * Precedence mirrors spatie's `ContextableData`/`Wrap`: a class that renders ITSELF unwrapped beats
 * everything, then a class-level `defaultWrap()` override, then the global `config('data.wrap')`
 * injected by the service provider, else unwrapped.
 *
 * > Every question here is asked of the {@see WrapReason}s standing for the class and of nothing else.
 * > The envelope comes off where a reason that unwraps the ROOT stands; wrapping still executes under
 * > the root unless one that PROPAGATES does. A reason that settles neither — the read saw the
 * > vocabulary and could not attribute it — leaves both open: the class keeps the envelope its
 * > configuration gives it and the author is told ({@see diagnose()}), and the nested report goes
 * > quiet rather than guess which switch it was.
 *
 * That fall is a choice, not the obvious one — omitting an envelope that is there and publishing one
 * that is not cost a client the same runtime failure. It falls this way because with `data.wrap`
 * configured the envelope is what the framework puts on EVERY root Data response: keeping it asserts
 * only the configuration, which was read, while dropping it would assert an override that was not.
 *
 * Reads are static AST reads over method bodies, never invoked, and over the class's OWN declarations
 * — a file may hold more than one class. Two things they do not reach: an unwrapping inherited from a
 * parent or a trait, and a runtime `wrap('key')`. The key's read is the one that follows a file rather
 * than a class, since `defaultWrap()` may arrive through a trait; the base `Data` declares none, so
 * `method_exists` being true already means a real override. Answers are memoised per FQCN.
 *
 * {@see DataSchema} applies the key at the response root only — deliberately, since a nested Data
 * property publishes a shared `$ref` that must not carry one caller's envelope. Spatie itself does wrap
 * a nested COLLECTION, which is a divergence {@see NestedCollectionWrap} reports rather than one this
 * class resolves.
 */
final class WrapResolver
{
    /** @var array<string, string|null> FQCN → resolved wrap key */
    private array $keys = [];

    /** @var array<string, WrapUncertainty|null> FQCN → why the envelope is unsettled, if it is */
    private array $unsettled = [];

    /** @var array<string, array<string, WrapReason>> FQCN → the reasons its own source raises */
    private array $standing = [];

    /** @var array<string, array<string, ClassMethod>> file → every class-method node in it */
    private array $parsed = [];

    /** @var array<string, array<string, ClassMethod>> file + FQCN → the method nodes that class declares */
    private array $declared = [];

    public function __construct(private readonly ?string $globalWrap = null) {}

    /**
     * The global `config('data.wrap')` alone, ignoring any class override.
     *
     * This is the key spatie puts a NESTED collection under: it resolves that envelope from the global
     * config, so an item class's own `defaultWrap()` does not change it. {@see key()} is the root's
     * question and answers the class first.
     */
    public function globalKey(): ?string
    {
        return $this->globalWrap;
    }

    /**
     * The wrap key, or null when unwrapped. Pass null for a collection — it has no single owning class,
     * so only the global key applies.
     */
    public function key(?string $fqcn): ?string
    {
        if ($fqcn === null) {
            return $this->globalWrap;
        }

        $this->decide($fqcn);

        return $this->keys[$fqcn];
    }

    /**
     * The diagnostic a root whose envelope could not be settled earns, or null where it was settled.
     *
     * It is only ever raised where an envelope IS applied under doubt: with no wrap configured and no
     * override there is no envelope either way, so there would be nothing for a reader to act on.
     *
     * The sentence names the envelope this read applied rather than the one the finished response
     * carries, which an overlay or a later statement can still answer for
     * (docs/design/defect-classes.md §"A diagnostic that asserts an outcome it never reads").
     */
    public function diagnose(string $fqcn): ?Diagnostic
    {
        $this->decide($fqcn);

        $unsettled = $this->unsettled[$fqcn];
        $key = $this->keys[$fqcn];

        if ($unsettled === null || $key === null) {
            return null;
        }

        return new Diagnostic(
            severity: Severity::Warning,
            code: 'spatie-data.root-wrap-unsettled',
            message: sprintf(
                'The response shape recovered for %s carries a {"%s": … } envelope because `data.wrap` resolves to it, but %s — so whether the envelope is really sent could not be established.',
                $fqcn,
                $key,
                $unsettled->because(),
            ),
            help: $unsettled->help(),
        );
    }

    /**
     * Whether spatie's wrapping still EXECUTES for values nested inside this class's root.
     *
     * This is the axis {@see WrapReason::propagates()} carries, not the one {@see key()} reads: a
     * class that takes only its OWN envelope off still sends a wrapped nested collection, and
     * {@see NestedCollectionWrap} has to hear about it.
     *
     * An unreadable `defaultWrap()` never reaches this answer — that is doubt about the root's KEY,
     * and a nested collection takes the global one whatever the class named.
     */
    public function wrapsNested(string $fqcn): bool
    {
        $this->decide($fqcn);

        return self::nestedStaysWrapped($this->standing[$fqcn]);
    }

    /** Fills the memos for a class. */
    private function decide(string $fqcn): void
    {
        if (array_key_exists($fqcn, $this->keys)) {
            return;
        }

        $this->keys[$fqcn] = $this->globalWrap;
        $this->unsettled[$fqcn] = null;
        $this->standing[$fqcn] = [];

        if (! class_exists($fqcn)) {
            return;
        }

        $file = (new ReflectionClass($fqcn))->getFileName();
        $standing = $this->standing[$fqcn] = WrapSightings::standing($file === false ? [] : $this->methodsOf($file, $fqcn));

        if (self::dropsEnvelope($standing)) {
            $this->keys[$fqcn] = null;

            return;
        }

        $overridden = method_exists($fqcn, 'defaultWrap');
        $declared = $overridden ? $this->defaultWrap($fqcn) : null;

        $this->keys[$fqcn] = $declared ?? $this->globalWrap;
        $this->unsettled[$fqcn] = match (true) {
            $overridden && $declared === null => WrapUncertainty::DefaultWrapNotLiteral,
            self::leavesItInDoubt($standing) => WrapUncertainty::DisablingNotAttributed,
            default => null,
        };
    }

    /**
     * Whether the envelope comes off. Only a reason that unwraps the root authorises it, and strength
     * does not enter — the reason that settles nothing does not unwrap the root in the first place.
     *
     * @param  array<string, WrapReason>  $standing
     */
    private static function dropsEnvelope(array $standing): bool
    {
        foreach ($standing as $reason) {
            if ($reason->unwrapsRoot()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the envelope this class keeps is nonetheless in doubt. A reason stands that settles
     * nothing, so the read saw the vocabulary and could not say whose it was — and nothing settled the
     * root either way, since a reading that names its receiver decides it whatever else stands.
     *
     * @param  array<string, WrapReason>  $standing
     */
    private static function leavesItInDoubt(array $standing): bool
    {
        if (self::dropsEnvelope($standing)) {
            return false;
        }

        foreach ($standing as $reason) {
            if (! $reason->isConclusive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether values nested under the root are still wrapped. A reason that propagates takes them
     * bare with the root; one that settles nothing takes the answer away entirely, because half the
     * switches it could have been do reach down here — so the report goes quiet rather than name a
     * divergence that may not exist.
     *
     * @param  array<string, WrapReason>  $standing
     */
    private static function nestedStaysWrapped(array $standing): bool
    {
        foreach ($standing as $reason) {
            if ($reason->propagates() || ! $reason->isConclusive()) {
                return false;
            }
        }

        return true;
    }

    /** The literal an overridden `defaultWrap()` returns, or null when it's dynamic. */
    private function defaultWrap(string $fqcn): ?string
    {
        $method = new ReflectionMethod($fqcn, 'defaultWrap');
        $file = $method->getFileName();

        if ($file === false) {
            return null;
        }

        // The declaring OWNER, so a sibling class in the same file cannot answer for this one. A trait
        // reports the using class as its declarer while naming the trait's file, which matches nothing
        // there — so that read falls back to the file, where the method name is the only key there is.
        $owner = $method->getDeclaringClass()->getName();
        $node = $this->methodsOf($file, $owner)['defaultWrap']
            ?? $this->methods($file)['defaultWrap']
            ?? null;

        return $node === null ? null : self::literalReturn($node);
    }

    /**
     * @return array<string, ClassMethod>
     */
    private function methods(string $file): array
    {
        return $this->parsed[$file] ??= ParsedClassFile::methods($file);
    }

    /**
     * @return array<string, ClassMethod>
     */
    private function methodsOf(string $file, string $fqcn): array
    {
        return $this->declared[$file.'::'.$fqcn] ??= ParsedClassFile::methodsOf($file, $fqcn);
    }

    /** The first `return '<literal>';` in a body, or null when every return is dynamic. */
    private static function literalReturn(ClassMethod $method): ?string
    {
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Return_::class) as $return) {
            if ($return->expr instanceof String_) {
                return $return->expr->value;
            }
        }

        return null;
    }
}
