<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\PlainText;
use Docuccino\Core\Versioning\VersionOrder;
use Docuccino\Laravel\Routing\AttributeCollector;
use Docuccino\Laravel\Support\DeclaredClasses;
use ReflectionClass;

/**
 * Reads `documents.*.api_version.changes` into the version changes that document derives an older
 * shape from. Reflection only: an attribute argument is a constant expression the compiler already
 * settled, so nothing here parses, folds or executes a line of the application.
 *
 * The answer is ordered `since` descending then FQCN ascending — the order the changes are APPLIED in,
 * newest first, so each hands the shape of the version below it to the next. Nothing depends on the
 * filesystem's enumeration order, and nothing depends on WHICH configured directory a class came out
 * of either: a modular application's `modules/Zebra` change sorts against an `app/` one by version and
 * class name alone, so adding a module cannot reorder anybody else's history.
 *
 * "Descending" is {@see VersionOrder}'s reading of the versions, never `strcmp`: bytewise, `1.10.0`
 * comes before `1.9.0` and a change list quietly applies backwards. The order is the one the document's
 * `versioning` keyword names, falling back to the one the versions are plainly written in, so an
 * application that spells its versions as dates or as semver never has to say so twice.
 *
 * @internal
 */
final readonly class VersionChangeCollector
{
    public function __construct(
        private string $basePath,
        private AttributeCollector $attributes = new AttributeCollector,
    ) {}

    public function collect(DocumentConfig $document): VersionChangeSet
    {
        [$directories, $diagnostics] = ChangeDirectories::resolve($this->basePath, $document);

        $changes = [];

        foreach ($directories as $dir) {
            // A directory the resolver returned but the filesystem has not got — it has already said
            // so, and opening one raises out of the iterator rather than reading nothing.
            if (! is_dir($dir)) {
                continue;
            }

            foreach (DeclaredClasses::in($dir) as $class) {
                $change = $this->declare($class, $diagnostics);
                if ($change !== null) {
                    $changes[] = $change;
                }
            }
        }

        return $this->ordered($changes, $document, $diagnostics);
    }

    /**
     * Sorts the changes newest first under the document's own version order, dropping any whose version
     * that order cannot read. A change the order cannot place is not applied to anything: its position
     * in the list decides which shape every later change is handed, so guessing one would rewrite the
     * document rather than merely omit a field.
     *
     * @param  list<VersionChange>  $changes
     * @param  list<Diagnostic>  $diagnostics
     */
    private function ordered(array $changes, DocumentConfig $document, array $diagnostics): VersionChangeSet
    {
        $versions = array_map(static fn (VersionChange $change): string => $change->since, $changes);
        $stated = $document->apiVersion();
        if ($stated !== null) {
            $versions[] = $stated;
        }

        // The keyword the document states wins; where it states none, the versions themselves say which
        // grammar they are written in. That is a derived default rather than a second source: an
        // application spelling its versions plainly never has to spell them out again in config.
        $order = VersionOrder::for($document->versioning) ?? VersionOrder::detect($versions);

        // Two ways a change list cannot be placed: no grammar reads the set whole, or one does and this
        // document's own version is not written in it. Two guards rather than one flag, so that what
        // makes `$stated` a string in the second message is the `if` above it rather than a fact an
        // analyser has to carry through a boolean.
        if ($order === null) {
            return $this->unordered(sprintf(
                'The versions this document declares are neither all dates nor all semver, so %d declared change(s) could not be placed in order and none was applied.',
                count($changes),
            ), $changes, $diagnostics);
        }

        if ($stated !== null && ! $order->reads($stated)) {
            return $this->unordered(sprintf(
                'This document\'s version "%s" is not a %s version, so %d declared change(s) could not be placed in order and none was applied.',
                PlainText::of($stated),
                $order->name(),
                count($changes),
            ), $changes, $diagnostics);
        }

        $placed = [];
        foreach ($changes as $change) {
            if ($order->reads($change->since)) {
                $placed[] = $change;

                continue;
            }

            $diagnostics[] = self::unapplicable($change->class, sprintf(
                'its version "%s" is not a %s version, so nothing can tell whether it shipped before or after this document\'s',
                PlainText::of($change->since),
                $order->name(),
            ), 'Write the version the way this document writes its own — `2026-09-01` for a date order, `1.2.0` for semver.');
        }

        usort($placed, static fn (VersionChange $a, VersionChange $b): int => ($order->compare($b->since, $a->since) ?? 0) ?: strcmp($a->class, $b->class));

        return new VersionChangeSet($placed, $order, $diagnostics);
    }

    /**
     * @param  class-string  $class
     * @param  list<Diagnostic>  $diagnostics
     */
    private function declare(string $class, array &$diagnostics): ?VersionChange
    {
        $reflection = new ReflectionClass($class);

        $attributes = $this->attributesOf($reflection, $diagnostics);
        $declaration = $attributes->first(ApiVersionChange::class);
        if ($declaration === null) {
            // A helper sitting beside the changes is not a change. Nothing to report: the directory is
            // the application's, and only an #[ApiVersionChange] claims to be read from it.
            return null;
        }

        $since = trim($declaration->since);
        if ($since === '') {
            $diagnostics[] = self::unapplicable($class, 'its #[ApiVersionChange] names no version, so nothing can tell which versions it applies to');

            return null;
        }

        $selectors = [];
        foreach ($attributes->all(AppliesTo::class) as $appliesTo) {
            $selector = trim($appliesTo->operation);
            if ($selector === '') {
                $diagnostics[] = self::unapplicable($class, 'one of its #[AppliesTo] declarations names no operation');

                continue;
            }

            $selectors[] = $selector;
        }

        return new VersionChange(
            class: $class,
            since: $since,
            description: trim($declaration->description),
            verbs: VerbOrder::read($attributes, $class, $diagnostics),
            selectors: array_values(array_unique($selectors)),
        );
    }

    /**
     * The one mint for "this declaration cannot be applied as it is written". `$help` overrides the
     * default remedy where the problem is not about how the rename is spelled — a diagnostic sending the
     * reader to a line that is already right is worse than none.
     */
    public static function unapplicable(string $class, string $problem, ?string $help = null): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.change-invalid',
            message: sprintf('%s was skipped: %s.', PlainText::of($class), $problem),
            help: $help ?? 'A change declares what the API did BEFORE its version: `to:` is the field name in the code today, `from:` the one older versions publish.',
        );
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @param  list<Diagnostic>  $diagnostics
     */
    private function attributesOf(ReflectionClass $class, array &$diagnostics): AttributeSet
    {
        return $this->attributes->collectOne(
            $class,
            $class->getName(),
            static function (Diagnostic $diagnostic) use (&$diagnostics): void {
                $diagnostics[] = $diagnostic;
            },
        );
    }

    /**
     * A change list nothing can order: the diagnostic, and the empty set that leaves every operation at
     * the head shape. Silent when no change was declared — there is nothing to fail to apply.
     *
     * @param  list<VersionChange>  $changes
     * @param  list<Diagnostic>  $diagnostics
     */
    private function unordered(string $message, array $changes, array $diagnostics): VersionChangeSet
    {
        if ($changes !== []) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'versioning.unordered',
                message: $message,
                help: 'Write every version the same way — `2026-09-01` or `1.2.0` — and set documents.*.versioning to `date` or `semver` to say which.',
            );
        }

        return new VersionChangeSet([], null, $diagnostics);
    }
}
