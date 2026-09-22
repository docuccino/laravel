<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Schema\EnumDecoration;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Laravel\Support\ListValueNames;

/**
 * The two value-set verbs as the transformer applies them, which is one edit with a switch: whether the
 * versions before the change published the value. `#[AddedEnumValue]` says they did not, so the derived
 * set drops it; `#[RemovedEnumValue]` says they did, so the derived set lists it again.
 *
 * What makes this more than an array edit is that an enum's decoration is POSITIONAL. The member names
 * a generated client takes its identifiers from and the per-value prose a viewer renders are parallel
 * to `enum`, applied by index and with no dedupe of their own — so a value spliced out of the list
 * while the arrays beside it kept their length would hand every member past it the previous one's name
 * in somebody's SDK. The edit is therefore made to the three together and the whole set is re-decorated
 * through {@see EnumDecoration}, which is the one rulebook that decides the shapes: the derived
 * document's set is decorated by the same rules the built one's was, rather than by a second copy of
 * them here.
 *
 * @internal
 */
final readonly class EnumValueEdit implements VersionVerb
{
    /**
     * Everything published parallel to `enum`, which is what an edit to the set has to rebuild rather
     * than carry over. {@see EnumDecoration} owns which of them are emitted and when.
     *
     * @var list<string>
     */
    private const array DECORATION = ['x-enum-varnames', 'x-enumNames', 'x-enumDescriptions', 'x-enum-descriptions'];

    /**
     * @param  string  $enum  the enum class the declaration names
     * @param  string|int  $value  the value, as the wire carries it
     * @param  bool  $publishedBefore  whether the versions before the change published it
     * @param  string  $name  the member name older clients knew it by, or empty to mint one
     * @param  string  $description  what the value meant, for the consumer reading it
     */
    public function __construct(
        private string $enum,
        private string|int $value,
        private bool $publishedBefore,
        private string $name = '',
        private string $description = '',
    ) {}

    public function schema(): string
    {
        return $this->enum;
    }

    public function facet(): SchemaFacet
    {
        return SchemaFacet::Values;
    }

    public function identity(IdentityGenerator $identity): string
    {
        return $this->facet()->identityOf($this->enum, $identity);
    }

    public function apply(array $schema, PublishedSchemas $published, VerbOutcome &$outcome): array
    {
        $values = $schema['enum'] ?? null;

        if (! is_array($values) || $values === []) {
            // The document publishes the class, and what it publishes is not a value set — a schema
            // whose `enum` the representation policy left off, or a class that is not the enum the
            // declaration takes it for.
            $outcome = $outcome->strongest(VerbOutcome::Absent);

            return $schema;
        }

        $values = array_values($values);
        $index = array_search($this->value, $values, true);

        // Strictly, because `0`, `'0'` and `false` are three values a set can publish side by side and
        // the declaration states which one it means. The same reading the canonicalizer holds `enum` to.
        if (($index !== false) === $this->publishedBefore) {
            $outcome = $outcome->strongest(VerbOutcome::Declined);

            return $schema;
        }

        $names = self::namesIn($schema, $values);
        $prose = self::proseIn($schema, $values);

        [$values, $names, $prose] = $index === false
            ? $this->added($values, $names, $prose)
            : self::dropped($values, $names, $prose, $index);

        $outcome = VerbOutcome::Applied;

        return self::decorate($schema, $values, $names, $prose);
    }

    public function rewriteDocumentExamples(array $doc, string $id, VersionChange $change): array
    {
        // An example carries a VALUE rather than a key, and nothing about this edit moves a key. An
        // example holding the value a version added is a real disagreement with the derived set, and it
        // is one the contract assertions catch against the wire rather than one to guess at here.
        return [$doc, []];
    }

    public function rewriteOperationExamples(array $operation, array $doc, string $id, array $keys, VersionChange $change): array
    {
        return [$operation, []];
    }

    public function diagnose(VerbOutcome $outcome, VersionChange $change, PublishedSchemas $published): ?Diagnostic
    {
        return match ($outcome) {
            VerbOutcome::Unresolved => VerbDiagnostics::schemaUnresolved($change, $this),
            VerbOutcome::Declined => $this->unchanged($change),
            VerbOutcome::Absent => $this->notASet($change),
            VerbOutcome::Applied => $this->nameUnusable($change, $published) ?? $this->proseLost($change, $published),
        };
    }

    /**
     * The value put back, with the member name older clients knew it by and whatever prose the
     * declaration wrote for it.
     *
     * **The name is minted from the VALUE, and its neighbours' came from the enum's CASE NAMES.** A
     * deleted case has no name left to read, so there is nothing else to mint from — but the two
     * rulebooks can disagree, which is why `name:` exists and why an author who knows what older
     * clients called the value should state it.
     *
     * A minted name that collides with one already in the set is the one case that cannot be published:
     * generators apply these by index and without a dedupe, so two members would share an identifier.
     * The set then publishes NO name hints rather than a colliding pair, and {@see nameUnusable()} says
     * so — widening rather than guessing, and the remedy is one argument away.
     *
     * @param  list<mixed>  $values
     * @param  list<string>  $names
     * @param  array<string, string>  $prose
     * @return array{0: list<mixed>, 1: list<string>, 2: array<string, string>}
     */
    private function added(array $values, array $names, array $prose): array
    {
        $values[] = $this->value;

        if ($names !== []) {
            $name = self::memberName($this->name, $this->value);

            $names = in_array($name, $names, true) ? [] : [...$names, $name];
        }

        $description = trim($this->description);
        if ($description !== '') {
            $prose[(string) $this->value] = $description;
        }

        return [$values, $names, $prose];
    }

    /**
     * The value taken out, and the name and prose that were its and no other member's.
     *
     * @param  list<mixed>  $values
     * @param  list<string>  $names
     * @param  array<string, string>  $prose
     * @return array{0: list<mixed>, 1: list<string>, 2: array<string, string>}
     */
    private static function dropped(array $values, array $names, array $prose, int $index): array
    {
        $key = is_scalar($values[$index]) ? (string) $values[$index] : '';

        unset($values[$index], $names[$index], $prose[$key]);

        return [array_values($values), array_values($names), $prose];
    }

    /**
     * The edited set, re-decorated. Every decoration key is dropped first and then rebuilt: the map form
     * of the prose is emitted only where EVERY value carries some, so a set that loses that property by
     * gaining a value has to lose the map with it rather than keep a stale one.
     *
     * @param  array<array-key, mixed>  $schema
     * @param  list<mixed>  $values
     * @param  list<string>  $names
     * @param  array<string, string>  $prose
     * @return array<array-key, mixed>
     */
    private static function decorate(array $schema, array $values, array $names, array $prose): array
    {
        $naming = EnumDecoration::namingOf($schema);

        // Rebuilt rather than unset in place, because what goes back to the decoration is a JSON OBJECT
        // and a schema read off a document is keyed by whatever was in it.
        $rebuilt = [];
        foreach ($schema as $key => $value) {
            if (! in_array($key, self::DECORATION, true)) {
                $rebuilt[(string) $key] = $value;
            }
        }

        $rebuilt['enum'] = $values;

        return EnumDecoration::apply($rebuilt, $naming, $names, $prose);
    }

    /**
     * The member names the set publishes, parallel to its values — or none, where it publishes no names
     * or publishes a list that was never parallel to anything. Short names are dropped here rather than
     * carried and dropped later, so the edit below is made to a set that is either wholly named or not
     * named at all.
     *
     * @param  array<array-key, mixed>  $schema
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private static function namesIn(array $schema, array $values): array
    {
        $names = $schema['x-enum-varnames'] ?? $schema['x-enumNames'] ?? null;

        if (! is_array($names) || count($names) !== count($values)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $name): string => is_scalar($name) ? (string) $name : '', $names));
    }

    /**
     * The per-value prose, keyed by the value it belongs to. The map form is read first because it says
     * which value each sentence is about; the positional array is read as the fallback, zipped against
     * the values it was published parallel to.
     *
     * @param  array<array-key, mixed>  $schema
     * @param  list<mixed>  $values
     * @return array<string, string>
     */
    private static function proseIn(array $schema, array $values): array
    {
        $keys = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $values);

        $map = $schema['x-enumDescriptions'] ?? null;
        $map = is_object($map) ? get_object_vars($map) : $map;

        if (is_array($map)) {
            $prose = [];
            foreach ($map as $key => $text) {
                if (is_string($text) && $text !== '') {
                    $prose[(string) $key] = $text;
                }
            }

            return $prose;
        }

        $texts = $schema['x-enum-descriptions'] ?? null;
        if (! is_array($texts) || count($texts) !== count($values)) {
            return [];
        }

        $prose = [];
        foreach (array_values($texts) as $index => $text) {
            if (is_string($text) && $text !== '') {
                $prose[$keys[$index]] = $text;
            }
        }

        return $prose;
    }

    /** The declaration read the other way round: the set already says what this version would make it say. */
    private function unchanged(VersionChange $change): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.change-target-unchanged',
            message: sprintf(
                '%s declares that %s %s the value %s, and the set %s publishes already %s it, so this version says what the code says.',
                $change->class,
                $this->enum,
                $this->publishedBefore ? 'stopped publishing' : 'gained',
                self::quoted($this->value),
                $this->enum,
                $this->publishedBefore ? 'leaves it out' : 'carries it',
            ),
            help: sprintf(
                '%s names a value the code %s today — read the other way round it describes a change nobody made. Retire the declaration if the set has changed again since.',
                $this->publishedBefore ? '#[RemovedEnumValue]' : '#[AddedEnumValue]',
                $this->publishedBefore ? 'no longer publishes' : 'publishes',
            ),
        );
    }

    /** The document publishes the class, and what it publishes carries no value set to move. */
    private function notASet(VersionChange $change): Diagnostic
    {
        return VerbDiagnostics::targetMissing($change, sprintf(
            'names the value %s of %s, whose schema publishes no value set at all',
            self::quoted($this->value),
            $this->enum,
        ), 'value');
    }

    /**
     * Applied, and the set lost the prose map doing it. The map is emitted only where every value
     * carries a sentence — that completeness is the extension's contract, and a viewer hides the values
     * missing from it — so putting a value back without one costs the whole set its map. Nothing else
     * would tell the author which declaration did that, and the remedy is one argument away.
     */
    private function proseLost(VersionChange $change, PublishedSchemas $published): ?Diagnostic
    {
        if (! $this->publishedBefore || trim($this->description) !== '') {
            return null;
        }

        $ref = $published->refFor($this->enum, SchemaFacet::Values);
        $body = $ref === null ? null : $published->body($ref);

        if ($body === null || ! isset($body['x-enumDescriptions'])) {
            return null;
        }

        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.enum-prose-dropped',
            message: sprintf(
                '%s puts the value %s back into %s with no description, and the set describes every other value, so this version publishes the set without its descriptions.',
                $change->class,
                self::quoted($this->value),
                $this->enum,
            ),
            help: 'Give #[RemovedEnumValue] a `description:` saying what the value meant. The map is published only where every value has one, because a reader hides the values missing from it.',
        );
    }

    /** What older clients called the value: what the author stated, or a pure function of the value. */
    private static function memberName(string $declared, string|int $value): string
    {
        $declared = trim($declared);

        return $declared === '' ? ListValueNames::names([(string) $value])[0] : $declared;
    }

    /**
     * Applied, and the set lost its member names doing it — the minted or declared name was one the set
     * already publishes, and two members sharing an identifier is what a generated client cannot have.
     */
    private function nameUnusable(VersionChange $change, PublishedSchemas $published): ?Diagnostic
    {
        if (! $this->publishedBefore) {
            return null;
        }

        $ref = $published->refFor($this->enum, SchemaFacet::Values);
        $body = $ref === null ? null : $published->body($ref);
        $names = $body === null ? null : ($body['x-enum-varnames'] ?? $body['x-enumNames'] ?? null);

        if (! is_array($names)) {
            return null;
        }

        $name = self::memberName($this->name, $this->value);

        if (! in_array($name, $names, true)) {
            return null;
        }

        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.enum-name-contested',
            message: sprintf(
                '%s puts the value %s back into %s under the member name "%s", which the set already publishes, so this version publishes the set without any member names.',
                $change->class,
                self::quoted($this->value),
                $this->enum,
                $name,
            ),
            help: 'Two members sharing a name become one identifier in a generated client. Give #[RemovedEnumValue] a `name:` that the set does not already use — the one older clients knew the value by.',
        );
    }

    /** A value as a report spells it, with a string's quotes and a number's absence of them. */
    private static function quoted(string|int $value): string
    {
        return is_int($value) ? (string) $value : '"'.$value.'"';
    }
}
