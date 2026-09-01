<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\ChangedFieldExamples;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\PlainText;

/**
 * `#[RemovedResponseField]` as the transformer applies it: the field the change deleted goes back into
 * `properties`, and into `required` where the versions before it always sent it.
 *
 * It is the only verb that ADDS to a schema, and so the only one that has to know what to add. Every
 * other verb names a field the code still carries and moves what the document says about it; here the
 * field is gone, which is why the declaration carries a type at all. {@see shape()} is the whole of how
 * that type is read, and it reaches for no converter: a class the document already publishes becomes a
 * pointer at that component, one of OpenAPI's own type names becomes that `type`, and anything else
 * publishes an unconstrained field with a diagnostic beside it — valid and vague beats precise and
 * false.
 *
 * The `$ref` reading composes for free, because deriving a version rewrites the WHOLE document: the
 * component it points at is itself downgraded by every other change, so a field re-added as an
 * `Invoice` in the 2026-01-01 document holds the 2026-01-01 `Invoice`.
 *
 * @internal
 */
final readonly class RemovedEdit implements VersionVerb
{
    /**
     * @param  string  $schema  the class the declaration names
     * @param  string  $field  the field as the versions before the change published it
     * @param  string  $type  a published class, an OpenAPI type name, or empty for no shape at all
     * @param  bool  $required  whether those versions always sent it
     */
    public function __construct(
        private string $schema,
        private string $field,
        private string $type,
        private bool $required,
        private string $description,
    ) {}

    public function schema(): string
    {
        return $this->schema;
    }

    public function facet(): SchemaFacet
    {
        return SchemaFacet::Response;
    }

    public function identity(IdentityGenerator $identity): string
    {
        return $this->facet()->identityOf($this->schema, $identity);
    }

    public function apply(array $schema, PublishedSchemas $published, VerbOutcome &$outcome): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        if (array_key_exists($this->field, $properties)) {
            // The code still publishes a field of that name, so this version removed nothing — the same
            // disagreement between declaration and code the required-ness verbs report, in the one
            // direction this verb can see it.
            $outcome = $outcome->strongest(VerbOutcome::Declined);

            return $schema;
        }

        $schema['properties'] = MemberOrder::intoProperties($properties, $this->field, $this->property($published));

        if ($this->required) {
            // Load-bearing rather than cosmetic: `required` is the only place the document says the
            // field will be there at all, and a version whose runtime really did always send it is
            // exactly what the per-version contract test can refuse.
            $required = is_array($schema['required'] ?? null) ? array_values($schema['required']) : [];
            $schema['required'] = MemberOrder::intoRequired($required, $schema['properties'], $this->field);
        }

        $outcome = VerbOutcome::Applied;

        return $schema;
    }

    public function rewriteDocumentExamples(array $doc, string $id, VersionChange $change): array
    {
        if (! $this->required) {
            return [$doc, []];
        }

        [$doc, $dropped] = ChangedFieldExamples::requiringInDocument($doc, $id, $this->field);

        return [$doc, $this->drops($dropped, $change)];
    }

    public function rewriteOperationExamples(array $operation, array $doc, string $id, array $keys, VersionChange $change): array
    {
        if (! $this->required) {
            return [$operation, []];
        }

        [$operation, $dropped] = ChangedFieldExamples::requiringInOperation($operation, $doc, $id, $this->field, $keys);

        return [$operation, $this->drops($dropped, $change)];
    }

    public function diagnose(VerbOutcome $outcome, VersionChange $change, PublishedSchemas $published): ?Diagnostic
    {
        return match ($outcome) {
            VerbOutcome::Unresolved => VerbDiagnostics::schemaUnresolved($change, $this),
            VerbOutcome::Declined => $this->stillPublished($change),
            // `Absent` is not reachable here — a removal asks the schema for a field it expects NOT to
            // find — so an applied one is the only case left, and it still owes the report below when
            // the shape it published was not the shape it was told to.
            default => $this->shape($published) === null ? $this->unreadableType($change) : null,
        };
    }

    /**
     * The subschema the field is published with: the shape `type:` names, plus the sentence the
     * declaration wrote for a consumer. An unreadable type publishes the empty schema — every instance
     * is valid against it, which is true of a field nobody can now describe — and {@see diagnose()}
     * says so.
     *
     * @return array<string, mixed>
     */
    private function property(PublishedSchemas $published): array
    {
        $shape = $this->shape($published) ?? [];

        if (trim($this->description) !== '') {
            // Beside a `$ref` rather than instead of it: OAS 3.1 lets a reference carry siblings, and
            // they annotate what they point at.
            $shape['description'] = trim($this->description);
        }

        return $shape;
    }

    /**
     * The three readings of `type:`, in order — a class this document publishes, one of OpenAPI's own
     * type names, and neither. Null is the third, which is the only one that owes a report; a
     * declaration that states no type at all asks for the unconstrained shape on purpose and gets it
     * without being told off for it.
     *
     * @return array<string, mixed>|null
     */
    private function shape(PublishedSchemas $published): ?array
    {
        $type = trim($this->type);

        if ($type === '') {
            return [];
        }

        $ref = $published->refFor(ltrim($type, '\\'), SchemaFacet::Response);

        return $ref === null ? OasTypeNames::read($type) : ['$ref' => $ref];
    }

    /**
     * The examples this version could not be given: it re-adds a field its own schema now demands, and
     * an example that does not carry it fails the schema published beside it.
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
                '%s puts the required field "%s" back on %s, and the example at %s does not carry it, so it was dropped rather than published failing its own schema.',
                PlainText::of($change->class),
                PlainText::of($this->field),
                PlainText::of($this->schema),
                PlainText::of($pointer),
            ),
            help: 'A consumer copies an example and sends it back, so one this version\'s schema '
                .'rejects is worse than none. Pin an example carrying the field beside the schema, or '
                .'declare the removal without `required: true` if that version did not always send it.',
        ), $dropped);
    }

    /** The declaration says the version removed the field, and the code still publishes it. */
    private function stillPublished(VersionChange $change): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.change-target-unchanged',
            message: sprintf(
                '%s declares that the response field "%s" of %s was removed, and the schema still publishes it, so this version says what the code says.',
                PlainText::of($change->class),
                PlainText::of($this->field),
                PlainText::of($this->schema),
            ),
            help: '#[RemovedResponseField] names a field the code no longer has — read the other way round it describes a removal nobody made. Retire the declaration if the field came back.',
        );
    }

    /** The declared type read as none of the three readings, and what was published instead. */
    private function unreadableType(VersionChange $change): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.type-unresolved',
            message: sprintf(
                '%s puts the field "%s" back on %s with the type "%s", which this document publishes no schema for and which is not an OpenAPI type name, so the field was published with no shape at all.',
                PlainText::of($change->class),
                PlainText::of($this->field),
                PlainText::of($this->schema),
                PlainText::of($this->type),
            ),
            help: sprintf(
                'Name a class this document publishes a response schema for, or one of %s — each may be suffixed `[]` for a list of them or `?` for one that may be null. Leave `type:` out to publish the field with no shape on purpose.',
                implode(', ', array_map(static fn (string $name): string => '`'.$name.'`', OasTypeNames::NAMES)),
            ),
        );
    }
}
