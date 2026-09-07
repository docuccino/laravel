<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Attributes\Versioning\MadeRequestFieldOptional;
use Docuccino\Attributes\Versioning\MadeResponseFieldOptional;
use Docuccino\Attributes\Versioning\MadeResponseFieldRequired;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Docuccino\Attributes\Versioning\RenamedParameter;
use Docuccino\Attributes\Versioning\RenamedRequestField;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Support\PlainText;
use Docuccino\Laravel\Support\ParameterLocations;

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
 * The rule holds unchanged for the renames that reach a REQUEST body and a parameter, and it holds for
 * the same reason rather than by extension. `#[RenamedRequestField]` renames a property of the request
 * shape, which `#[MadeRequestFieldOptional]` names as the code spells it today — the identical
 * before/after problem on the other half of the wire — so it goes last with its sibling.
 * `#[RenamedParameter]` moves a name no other verb in the vocabulary can address at all: nothing today
 * names a parameter, so there is no verb it could rot, and it is placed with the renames because that
 * is where a verb that changes what something is CALLED belongs the day one does. Ordering it by that
 * rule now rather than by its current independence is the whole point of stating the rule here.
 *
 * Within one type the author's order stands: a change renaming two fields renames them as written.
 * Between the required-ness verbs and the removal the order is not observable — each names one field
 * of one shape, and a change declaring two contradictory things about one field is a change to fix
 * rather than a precedence to define — but it is fixed here anyway, because "not observable today" is
 * not a property to leave to whichever call site is read first.
 *
 * `VersionChangeOrderTest` is the executed guard: swap the two halves of `read()` and it goes red.
 *
 * **Every author-written word is normalised here, once, before anything compares or stores it.** A
 * schema, a field and a rename's two ends are words somebody typed, and a factory that compared one as
 * typed while storing it trimmed reads that word twice and gets two answers: `from: 'q', to: ' q'`
 * moves nothing, and used to pass the self-rename refusal only to surface later as "would collapse two
 * parameters into one" about a parameter the author never renamed. Each factory below trims first and
 * every read after that is of the trimmed value, and the rename pair comes back out of
 * {@see renameable()} already normalised so a caller cannot re-derive it a third way.
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
     * @return list<VersionVerb|OperationVerb>
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
            $verbs[] = self::rename($declaration, SchemaFacet::Response, '#[RenamedResponseField]', $class, $diagnostics);
        }

        foreach ($attributes->all(RenamedRequestField::class) as $declaration) {
            $verbs[] = self::rename($declaration, SchemaFacet::Request, '#[RenamedRequestField]', $class, $diagnostics);
        }

        foreach ($attributes->all(RenamedParameter::class) as $declaration) {
            $verbs[] = self::parameterRename($declaration, $class, $diagnostics);
        }

        return array_values(array_filter($verbs));
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    private static function required(string $schema, string $field, SchemaFacet $facet, bool $requiredBefore, string $declaration, string $class, array &$diagnostics): ?RequiredEdit
    {
        $schema = trim($schema);
        $field = trim($field);

        if ($field === '' || $schema === '') {
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
        $schema = trim($removal->schema);
        $field = trim($removal->field);

        if ($field === '' || $schema === '') {
            $diagnostics[] = VersionChangeCollector::unapplicable(
                $class,
                'one of its #[RemovedResponseField] declarations leaves `schema:` or `field:` empty',
                'A change declares what the API did BEFORE its version, and a removal names the field as the versions before it published it.',
            );

            return null;
        }

        return new RemovedEdit(
            $schema,
            $field,
            $removal->type,
            $removal->required,
            $removal->description,
        );
    }

    /**
     * The declaration itself rather than its three strings: `from` and `to` are adjacent, same-typed
     * and transposable, and a caller spelling them the wrong way round would declare the reverse of the
     * change with nothing to catch it.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    private static function rename(RenamedResponseField|RenamedRequestField $rename, SchemaFacet $facet, string $declaration, string $class, array &$diagnostics): ?RenameEdit
    {
        $pair = self::renameable($rename->from, $rename->to, $declaration, $class, $diagnostics);

        if ($pair === null) {
            return null;
        }

        return new RenameEdit(trim($rename->schema), $pair['from'], $pair['to'], $facet);
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    private static function parameterRename(RenamedParameter $rename, string $class, array &$diagnostics): ?ParameterRenameEdit
    {
        $pair = self::renameable($rename->from, $rename->to, '#[RenamedParameter]', $class, $diagnostics);

        if ($pair === null) {
            return null;
        }

        // A location OpenAPI has not got names nothing to look for, and a parameter cannot be found by
        // name alone: two operations can carry `page` in the query and in the path, and renaming
        // whichever was met first would move a parameter the author did not name.
        $in = ParameterLocations::read($rename->in);

        if ($in === null) {
            $diagnostics[] = VersionChangeCollector::unapplicable($class, sprintf(
                'one of its #[RenamedParameter] declarations says `in: "%s"`, which names no parameter location',
                PlainText::of($rename->in),
            ), sprintf('A parameter is in one of %s, spelled in any case.', ParameterLocations::quoted()));

            return null;
        }

        // A path parameter is the one location whose name is stated TWICE — once on the parameter and
        // once as the `{expression}` of the path it stands under — and only the parameter is something
        // a version change can address. Renaming it alone publishes a template whose expression names
        // no parameter beside a parameter no expression names, which is invalid in both directions and
        // costs a generated client the operation or its URL builder. Refused rather than half-applied,
        // and refused rather than rewritten: nothing on the wire carries a path parameter's name — a
        // client sends `/things/42` — so no older version ever accepted another one, and moving the
        // expression would re-spell the path every id under that operation is minted from.
        if ($in === 'path') {
            $diagnostics[] = VersionChangeCollector::unapplicable($class, sprintf(
                'one of its #[RenamedParameter] declarations renames the path parameter "%s", and a path parameter is named by the URL template it stands under rather than by anything a client sends',
                PlainText::of($pair['to']),
            ), 'Nothing on the wire carries a path parameter\'s name, so no older version accepted it under another one. Rename a `query`, `header` or `cookie` parameter, and where the URL itself changed, publish the older one as a route of its own.');

            return null;
        }

        return new ParameterRenameEdit($in, $pair['from'], $pair['to']);
    }

    /**
     * The two ends of a rename, normalised — or null where the pair names nothing to move. The two
     * refusals every rename shares: a pair with an empty end names nothing, and a pair with two equal
     * ends declares a change nobody made.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return array{from: string, to: string}|null
     */
    private static function renameable(string $from, string $to, string $declaration, string $class, array &$diagnostics): ?array
    {
        $from = trim($from);
        $to = trim($to);

        if ($from === '' || $to === '') {
            $diagnostics[] = VersionChangeCollector::unapplicable($class, sprintf(
                'one of its %s declarations leaves `from:` or `to:` empty',
                $declaration,
            ));

            return null;
        }

        if ($from === $to) {
            $diagnostics[] = VersionChangeCollector::unapplicable($class, sprintf(
                'one of its %s declarations renames "%s" to itself',
                $declaration,
                PlainText::of($from),
            ));

            return null;
        }

        return ['from' => $from, 'to' => $to];
    }
}
