<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\ChangedFieldExamples;
use Docuccino\Core\Identity\IdentityGenerator;

/**
 * `#[RenamedResponseField]` and `#[RenamedRequestField]` as the transformer applies them: the property
 * goes back to the name older versions publish, and every example carrying it goes with it.
 *
 * One edit with one switch — WHICH of the class's published shapes it names. A rename is symmetric
 * across the wire in a way required-ness is not: the field is published on both halves either way, and
 * only what it is CALLED moves, so the two verbs really are one sentence read in two directions rather
 * than two sentences ({@see RequiredEdit} is the case where they are not). What the facet decides is the
 * node, and that is not cosmetic — a class that is a request body on the way in and a response on the
 * way out is two nodes with two identities, and a verb resolving the wrong one rewrites a schema the
 * author never named.
 *
 * @internal
 */
final readonly class RenameEdit implements VersionVerb
{
    public function __construct(
        private string $schema,
        private string $from,
        private string $to,
        private SchemaFacet $facet,
    ) {}

    public function schema(): string
    {
        return $this->schema;
    }

    public function facet(): SchemaFacet
    {
        return $this->facet;
    }

    public function identity(IdentityGenerator $identity): string
    {
        return $this->facet()->identityOf($this->schema, $identity);
    }

    public function apply(array $schema, PublishedSchemas $published, VerbOutcome &$outcome): array
    {
        $properties = $schema['properties'] ?? null;
        if (! is_array($properties) || ! array_key_exists($this->to, $properties)) {
            $outcome = $outcome->strongest(VerbOutcome::Absent);

            return $schema;
        }

        if (array_key_exists($this->from, $properties)) {
            $outcome = $outcome->strongest(VerbOutcome::Declined);

            return $schema;
        }

        // In place, so the property keeps its position and everything it carries — provenance included,
        // which travels with the node rather than being re-keyed beside it.
        $renamed = [];
        foreach ($properties as $name => $value) {
            $renamed[$name === $this->to ? $this->from : $name] = $value;
        }
        $schema['properties'] = $renamed;

        // Load-bearing: a required list still naming today's field would mark a body carrying the OLD
        // name invalid, and a body carrying the new one valid — the exact disagreement a per-version
        // contract test exists to catch.
        $required = $schema['required'] ?? null;
        if (is_array($required)) {
            $schema['required'] = array_values(array_map(
                fn (mixed $name): mixed => $name === $this->to ? $this->from : $name,
                $required,
            ));
        }

        $outcome = VerbOutcome::Applied;

        return $schema;
    }

    public function rewriteDocumentExamples(array $doc, string $id, VersionChange $change): array
    {
        [$doc, $dropped] = ChangedFieldExamples::inDocument($doc, $id, $this->from, $this->to);

        return [$doc, $this->drops($dropped, $change)];
    }

    public function rewriteOperationExamples(array $operation, array $doc, string $id, array $keys, VersionChange $change): array
    {
        [$operation, $dropped] = ChangedFieldExamples::inOperation($operation, $doc, $id, $this->from, $this->to, $keys);

        return [$operation, $this->drops($dropped, $change)];
    }

    public function diagnose(VerbOutcome $outcome, VersionChange $change, PublishedSchemas $published): ?Diagnostic
    {
        return match ($outcome) {
            VerbOutcome::Applied => null,
            VerbOutcome::Declined => VersionChangeCollector::unapplicable($change->class, sprintf(
                'the %sschema for %s already publishes a field called "%s", so renaming "%s" onto it would collapse two fields into one',
                $this->facet->schemaQualifier(),
                $this->schema,
                $this->from,
                $this->to,
            )),
            VerbOutcome::Absent => VerbDiagnostics::targetMissing($change, sprintf(
                'renames "%s", which the %sschema for %s no longer publishes',
                $this->to,
                $this->facet->schemaQualifier(),
                $this->schema,
            ), 'field'),
            VerbOutcome::Unresolved => VerbDiagnostics::schemaUnresolved($change, $this),
        };
    }

    /**
     * The examples this version could not be given, and where they stood. One per site rather than one
     * per rename: each is a different example, at a different pointer, and a reader fixing one is not
     * thereby told about the next.
     *
     * @param  list<string>  $dropped
     * @return list<Diagnostic>
     */
    private function drops(array $dropped, VersionChange $change): array
    {
        return array_map(fn (string $pointer): Diagnostic => new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.example-dropped',
            message: sprintf(
                '%s renames "%s" on %s, and the example at %s could not be rewritten to the shape this version publishes, so it was dropped.',
                $change->class,
                $this->to,
                $this->schema,
                $pointer,
            ),
            help: 'A consumer copies an example and sends it back, so one this version\'s schema '
                .'rejects is worse than none. The rewrite stops where the schema does not settle on '
                .'one shape for the example — a oneOf/anyOf branch, a `$ref` that leads back to '
                .'itself, a value that is not the kind of thing the schema describes, or an example '
                .'already carrying both names. Pin an example that matches the schema beside it, or '
                .'narrow the schema so one shape governs it.',
        ), $dropped);
    }
}
