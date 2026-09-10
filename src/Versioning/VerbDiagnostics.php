<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;

/**
 * Every report a verb owes that is not particular to what that verb does: a schema this document
 * publishes nowhere, a scope that decided nothing, a node that cannot be given a private copy, and a
 * target the code no longer spells. One mint each rather than one per verb, because the reader meeting
 * a second wording of the same fact reads it as a second problem.
 *
 * A request verb says so in as many words. `Foo` and `Foo`'s request body are two nodes, and a reader
 * told only that "this document publishes no schema for Foo" — while looking at a document that
 * plainly publishes one — would go looking for a bug that is not there.
 *
 * @internal
 */
final class VerbDiagnostics
{
    /**
     * How to spell an operation, which every scope refusal has to tell an author and none of them
     * should tell them differently. A trailing clause is the caller's, because what to check BESIDES
     * the spelling is what the two refusals differ on.
     */
    private const string NAME_THE_OPERATION = 'Write the operation the way the document names it — `GET /api/things`, an operationId, or either with a `*`';

    /** A verb naming a class this document publishes no such shape for. */
    public static function schemaUnresolved(VersionChange $change, VersionVerb $verb): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.schema-unresolved',
            message: sprintf(
                '%s names %s, which this document publishes no %sschema for, so the change was skipped and this version is left at the current shape.',
                $change->class,
                $verb->schema(),
                $verb->facet()->schemaQualifier(),
            ),
            help: 'Name the class whose shape the document actually publishes — a change can only rewrite a schema this document contains.',
        );
    }

    /**
     * A scoped change over a schema this document publishes for no operation at all. The scope decides
     * nothing, and editing anyway would rewrite the schema for every operation it was written to leave
     * out — so nothing is rewritten and this says why.
     */
    public static function publishedForNoOperation(VersionChange $change, VersionVerb $verb): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.scope-matches-nothing',
            message: sprintf(
                '%s is scoped with #[AppliesTo], and this document publishes the %sschema for %s for no operation at all, so the scope names nothing and the change was applied to nothing.',
                $change->class,
                $verb->facet()->schemaQualifier(),
                $verb->schema(),
            ),
            help: 'Check the document publishes that schema for the operations you named — a scoped change never edits a schema the scope cannot reach.',
        );
    }

    /**
     * A selector naming no operation this document publishes the schema for. Worth a warning because a
     * scope that matches nothing is indistinguishable from a change that was never declared: a route
     * renamed months later silently stops the change applying, and the version's document goes back to
     * saying what the code says without anything having been edited.
     */
    public static function scopeMatchesNothing(VersionChange $change, string $selector, VersionVerb $verb): Diagnostic
    {
        return self::selectorDecidedNothing(
            $change,
            $selector,
            sprintf(' %s for, so that part of the change applies to nothing.', $verb->schema()),
            self::NAME_THE_OPERATION.' — and check the document publishes that schema for it.',
        );
    }

    /**
     * The same for a verb that names no schema. Its own wording rather than the schema one's, because
     * there is no schema to say the document publishes it for — the operation either exists or it does
     * not.
     */
    public static function scopeNamesNoOperation(VersionChange $change, string $selector, OperationVerb $verb): Diagnostic
    {
        return self::selectorDecidedNothing(
            $change,
            $selector,
            sprintf(', so the rename of %s applies to nothing there.', $verb->declares()),
            self::NAME_THE_OPERATION.'.',
        );
    }

    /**
     * An operation the scope matched that cannot be given a private copy of the schema — the schema
     * contains itself, or the node it is published through is shared with operations the scope leaves
     * out. Its own code, because the remedy is the SCOPE rather than the declaration: nothing about the
     * change is written wrong, and telling the author to fix the declaration sends them to a line that
     * is already right.
     */
    public static function unforkable(VersionChange $change, string $problem): Diagnostic
    {
        return self::scopeUnforkable(
            $change,
            $problem,
            'Drop the #[AppliesTo], or widen it to every operation that publishes the schema, and the shared component is renamed in place instead.',
        );
    }

    /**
     * The same for a verb whose subject is the operation, whose help cannot be {@see unforkable()}'s:
     * there is no schema to widen the scope to, so the remedy is the scope or the shared path item
     * rather than a component.
     */
    public static function unnarrowable(VersionChange $change, string $problem): Diagnostic
    {
        return self::scopeUnforkable(
            $change,
            $problem,
            'Drop the #[AppliesTo], or widen it to every operation the shared path item publishes, and the rename is applied to it once.',
        );
    }

    /**
     * What the change names is not in the document any more, so there was nothing to undo. `$clause`
     * says what was looked for and where — a verb knows that and this does not — and `$noun` is the
     * kind of thing it was, which is the only word the remedy turns on.
     */
    public static function targetMissing(VersionChange $change, string $clause, string $noun): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.change-target-missing',
            message: sprintf(
                '%s %s, so this version still says what the code says.',
                $change->class,
                $clause,
            ),
            help: sprintf(
                'Update the change to name the %s as it is spelled today, or retire it if the %s is gone.',
                $noun,
                $noun,
            ),
        );
    }

    /** The sentence both scope-matched-nothing refusals open with, said once. */
    private static function selectorDecidedNothing(VersionChange $change, string $selector, string $consequence, string $help): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.scope-matches-nothing',
            message: sprintf(
                '%s is scoped to "%s", which names no operation this document publishes%s',
                $change->class,
                $selector,
                $consequence,
            ),
            help: $help,
        );
    }

    /** The sentence both unforkable refusals share, which is all of it but the remedy. */
    private static function scopeUnforkable(VersionChange $change, string $problem, string $help): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.scope-unforkable',
            message: sprintf('%s could not be narrowed as written: %s.', $change->class, $problem),
            help: $help,
        );
    }
}
