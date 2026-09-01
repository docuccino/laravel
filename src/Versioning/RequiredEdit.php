<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\PlainText;

/**
 * The three required-ness verbs as the transformer applies them, which is one edit with two switches:
 * WHICH shape the field belongs to, and whether the versions before the change named it in `required`.
 * `properties` is never touched — the field was published then and is published now; only the promise
 * about it moved.
 *
 * Required-ness is not symmetric across the wire, which is why the vocabulary spells the three out
 * rather than carrying a flag: `required` arriving NARROWS a request and moves nothing on a response,
 * so "made required" on the way in and on the way out are two different sentences to a consumer.
 *
 * @internal
 */
final readonly class RequiredEdit implements VersionVerb
{
    /**
     * @param  string  $schema  the class the declaration names
     * @param  string  $field  the field, as the code spells it today
     * @param  bool  $requiredBefore  whether the versions before the change named it in `required`
     * @param  string  $declaration  the attribute this reads, as a diagnostic spells it
     */
    public function __construct(
        private string $schema,
        private string $field,
        private SchemaFacet $facet,
        private bool $requiredBefore,
        private string $declaration,
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
        return $this->facet->identityOf($this->schema, $identity);
    }

    public function apply(array $schema, PublishedSchemas $published, VerbOutcome &$outcome): array
    {
        $properties = $schema['properties'] ?? null;
        if (! is_array($properties) || ! array_key_exists($this->field, $properties)) {
            $outcome = $outcome->strongest(VerbOutcome::Absent);

            return $schema;
        }

        $required = is_array($schema['required'] ?? null) ? array_values($schema['required']) : [];

        // The declaration says the field's required-ness CHANGED, so the code has to disagree with the
        // older shape or there was nothing to undo. Weaker than the rename's guard, which has a
        // distinguishable before and after: this one can only report that the code already says what
        // the older version would, never that the edit was applied twice.
        if (in_array($this->field, $required, true) === $this->requiredBefore) {
            $outcome = $outcome->strongest(VerbOutcome::Declined);

            return $schema;
        }

        $required = $this->requiredBefore
            ? MemberOrder::intoRequired($required, $properties, $this->field)
            : array_values(array_filter($required, fn (mixed $name): bool => $name !== $this->field));

        // Absence rather than `[]`: an empty `required` is a keyword saying nothing, and the
        // canonicalizer drops one — so writing it back would be a member no emitter publishes.
        if ($required === []) {
            unset($schema['required']);
        } else {
            $schema['required'] = $required;
        }

        $outcome = VerbOutcome::Applied;

        return $schema;
    }

    public function rewriteDocumentExamples(array $doc, string $id, VersionChange $change): array
    {
        return [$doc, []];
    }

    public function rewriteOperationExamples(array $operation, array $doc, string $id, array $keys, VersionChange $change): array
    {
        return [$operation, []];
    }

    public function diagnose(VerbOutcome $outcome, VersionChange $change, PublishedSchemas $published): ?Diagnostic
    {
        return match ($outcome) {
            VerbOutcome::Applied => null,
            VerbOutcome::Declined => $this->unchanged($change),
            VerbOutcome::Absent => $this->missing($change),
            VerbOutcome::Unresolved => VerbDiagnostics::schemaUnresolved($change, $this),
        };
    }

    /** What the change says the version DID, and what the versions before it said instead. */
    private function became(): string
    {
        return $this->requiredBefore ? 'optional' : 'required';
    }

    private function was(): string
    {
        return $this->requiredBefore ? 'required' : 'optional';
    }

    private function unchanged(VersionChange $change): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.change-target-unchanged',
            message: sprintf(
                '%s declares that the %s field "%s" of %s became %s, and the schema already publishes it as %s, so this version says what the code says.',
                PlainText::of($change->class),
                $this->facet->noun(),
                PlainText::of($this->field),
                PlainText::of($this->schema),
                $this->became(),
                $this->was(),
            ),
            help: sprintf(
                '%s says the field is %s in the code TODAY and was %s before — read the other way round it describes a change nobody made. Retire the declaration if the field has changed again since.',
                $this->declaration,
                $this->became(),
                $this->was(),
            ),
        );
    }

    private function missing(VersionChange $change): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.change-target-missing',
            message: sprintf(
                '%s names "%s", which the %sschema for %s no longer publishes, so this version still says what the code says.',
                PlainText::of($change->class),
                PlainText::of($this->field),
                $this->facet === SchemaFacet::Request ? 'request body ' : '',
                PlainText::of($this->schema),
            ),
            help: 'Update the change to name the field as it is spelled today, or retire it if the field is gone.',
        );
    }
}
