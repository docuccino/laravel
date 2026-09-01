<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Attributes\Versioning\MadeRequestFieldOptional;
use Docuccino\Attributes\Versioning\MadeResponseFieldOptional;
use Docuccino\Attributes\Versioning\MadeResponseFieldRequired;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Support\PlainText;

/**
 * The order one change's verbs are applied in, stated once and only here.
 *
 * It has to be stated somewhere, because the author's written order is not recoverable: an
 * {@see AttributeSet} answers PER ATTRIBUTE TYPE, so a change carrying two kinds of verb has already
 * lost which was written first by the time anything can ask.
 *
 * **The rule: a rename goes last, and everything else goes before it.** Every other verb names its
 * field the way the code spells it TODAY — that is the direction the whole vocabulary runs in — and a
 * rename is the one verb that changes what a field is called. Run a rename first and the property it
 * moved is standing under the older version's name, so a verb naming the same field finds nothing
 * there, edits nothing, and reports a declaration that is perfectly correct as rotted. Run the rename
 * last and every other verb sees the spelling it was written against, and the rename re-spells
 * `properties` and `required` together at the end.
 *
 * A removal is the one verb that names a field the code does NOT spell, so that sentence does not
 * cover it — and it still goes before the rename, for the other half of the same reason. A removal
 * PUTS a member back, and where it lands is counted against the names already standing
 * ({@see MemberOrder}); run the rename first and that count is taken against names this change itself
 * invented, so the position of a re-added field would be a function of another of the change's own
 * verbs. Run it before, and every insertion is counted against the schema the CODE publishes.
 *
 * Within one type the author's order stands: a change renaming two fields renames them as written.
 * Between the required-ness verbs and the removal the order is not observable — each names one field
 * of one shape, and a change declaring two contradictory things about one field is a change to fix
 * rather than a precedence to define — but it is fixed here anyway, because "not observable today" is
 * not a property to leave to whichever call site is read first.
 *
 * `VersionChangeOrderTest` is the executed guard: swap the two halves of `read()` and it goes red.
 *
 * @internal
 */
final class VerbOrder
{
    /**
     * One change's verbs, in the order they apply. Written as a run of explicit reads rather than a
     * loop over a table of class names: the table would be the order too, and this way the sequence is
     * legible in the file that owns it.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return list<VersionVerb>
     */
    public static function read(AttributeSet $attributes, string $class, array &$diagnostics): array
    {
        $verbs = [];

        foreach ($attributes->all(MadeResponseFieldRequired::class) as $declaration) {
            $verbs[] = self::required($declaration->schema, $declaration->field, SchemaFacet::Response, false, '#[MadeResponseFieldRequired]', $class, $diagnostics);
        }

        foreach ($attributes->all(MadeRequestFieldOptional::class) as $declaration) {
            $verbs[] = self::required($declaration->schema, $declaration->field, SchemaFacet::Request, true, '#[MadeRequestFieldOptional]', $class, $diagnostics);
        }

        foreach ($attributes->all(MadeResponseFieldOptional::class) as $declaration) {
            $verbs[] = self::required($declaration->schema, $declaration->field, SchemaFacet::Response, true, '#[MadeResponseFieldOptional]', $class, $diagnostics);
        }

        foreach ($attributes->all(RemovedResponseField::class) as $declaration) {
            $verbs[] = self::removal($declaration, $class, $diagnostics);
        }

        foreach ($attributes->all(RenamedResponseField::class) as $declaration) {
            $verbs[] = self::rename($declaration, $class, $diagnostics);
        }

        return array_values(array_filter($verbs));
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    private static function required(string $schema, string $field, SchemaFacet $facet, bool $requiredBefore, string $declaration, string $class, array &$diagnostics): ?RequiredEdit
    {
        if (trim($field) === '' || trim($schema) === '') {
            $diagnostics[] = VersionChangeCollector::unapplicable(
                $class,
                sprintf('one of its %s declarations leaves `schema:` or `field:` empty', $declaration),
                'A change declares what the API did BEFORE its version, and every verb but the rename names its field exactly as the code spells it today.',
            );

            return null;
        }

        return new RequiredEdit($schema, $field, $facet, $requiredBefore, $declaration);
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    private static function removal(RemovedResponseField $removal, string $class, array &$diagnostics): ?RemovedEdit
    {
        if (trim($removal->field) === '' || trim($removal->schema) === '') {
            $diagnostics[] = VersionChangeCollector::unapplicable(
                $class,
                'one of its #[RemovedResponseField] declarations leaves `schema:` or `field:` empty',
                'A change declares what the API did BEFORE its version, and a removal names the field as the versions before it published it.',
            );

            return null;
        }

        return new RemovedEdit(
            $removal->schema,
            trim($removal->field),
            $removal->type,
            $removal->required,
            $removal->description,
        );
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    private static function rename(RenamedResponseField $rename, string $class, array &$diagnostics): ?RenameEdit
    {
        if (trim($rename->from) === '' || trim($rename->to) === '') {
            $diagnostics[] = VersionChangeCollector::unapplicable($class, 'one of its #[RenamedResponseField] declarations leaves `from:` or `to:` empty');

            return null;
        }

        if ($rename->from === $rename->to) {
            $diagnostics[] = VersionChangeCollector::unapplicable($class, sprintf(
                'one of its #[RenamedResponseField] declarations renames "%s" to itself',
                PlainText::of($rename->from),
            ));

            return null;
        }

        return new RenameEdit($rename->schema, $rename->from, $rename->to);
    }
}
