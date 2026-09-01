<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Support\PlainText;

/**
 * The reports every verb owes alike: a schema this document publishes nowhere, and a scoped verb over
 * a schema it publishes for no operation. One mint each rather than one per verb, because the reader
 * meeting a second wording of the same fact reads it as a second problem.
 *
 * A request verb says so in as many words. `Foo` and `Foo`'s request body are two nodes, and a reader
 * told only that "this document publishes no schema for Foo" — while looking at a document that
 * plainly publishes one — would go looking for a bug that is not there.
 *
 * @internal
 */
final class VerbDiagnostics
{
    /** A verb naming a class this document publishes no such shape for. */
    public static function schemaUnresolved(VersionChange $change, VersionVerb $verb): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.schema-unresolved',
            message: sprintf(
                '%s names %s, which this document publishes no %sschema for, so the change was skipped and this version is left at the current shape.',
                PlainText::of($change->class),
                PlainText::of($verb->schema()),
                self::qualifier($verb),
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
                PlainText::of($change->class),
                self::qualifier($verb),
                PlainText::of($verb->schema()),
            ),
            help: 'Check the document publishes that schema for the operations you named — a scoped change never edits a schema the scope cannot reach.',
        );
    }

    /** The word that tells a response report from a request one, and nothing where there is nothing to tell. */
    private static function qualifier(VersionVerb $verb): string
    {
        return $verb->facet() === SchemaFacet::Request ? 'request body ' : '';
    }
}
