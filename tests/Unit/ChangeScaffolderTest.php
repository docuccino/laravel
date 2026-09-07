<?php

declare(strict_types=1);

use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RenamedParameter;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Laravel\Versioning\Scaffold\ChangeScaffolder;
use Docuccino\Laravel\Versioning\Scaffold\ScaffoldedChange;
use Docuccino\Laravel\Versioning\Scaffold\ScaffoldPlan;
use Workbench\App\Data\FormData;

/*
 * What the scaffolder DECLINES, which is the half a feature test over the happy path cannot reach.
 *
 * Every case here is a real difference the vocabulary does not express, and every one of them has to
 * come out as a sentence rather than as silence: a scaffold that wrote nothing and said nothing reads
 * as "nothing changed there", which costs the author a version document they believe is complete.
 *
 * The diff is the real {@see DocumentDiffer} over two real documents. Nothing here hand-builds a
 * changeset — a scaffolder tested against a changeset nobody computed proves only that it can read its
 * own fixtures.
 */

/** The node id both sides of every case below carry, so the differ pairs by identity. */
function scaffoldSchemaId(): string
{
    return 'sch:v1:scaffoldtest0001';
}

/**
 * One document publishing `FormData` with the given properties.
 *
 * @param  array<string, mixed>  $properties
 * @param  list<string>  $required
 */
function scaffoldDocument(array $properties, array $required = []): UirDocument
{
    $schema = [
        'x-docuccino' => ['id' => scaffoldSchemaId()],
        'type' => 'object',
        'properties' => $properties,
    ];

    if ($required !== []) {
        $schema['required'] = $required;
    }

    return UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms API', 'version' => '2026-09-01'],
        'paths' => [],
        'components' => ['schemas' => ['FormData' => $schema]],
    ]);
}

/** The plan for one pair of documents, with `FormData` published for `$source`. */
function scaffoldPlan(UirDocument $old, UirDocument $new, ?string $source = FormData::class): ScaffoldPlan
{
    $sources = $source === null ? [] : [scaffoldSchemaId() => $source];

    return (new ChangeScaffolder)->plan((new DocumentDiffer)->diff($old, $new), $old, $new, $sources, '2026-09-01');
}

/** @return list<string> */
function scaffoldClasses(ScaffoldPlan $plan): array
{
    return array_map(static fn (ScaffoldedChange $change): string => $change->class, $plan->changes);
}

it('declares a request field that stopped being required', function (): void {
    $plan = scaffoldPlan(
        scaffoldDocument(['note' => ['type' => 'string']], ['note']),
        scaffoldDocument(['note' => ['type' => 'string']]),
        FormData::class.'#request',
    );

    expect(scaffoldClasses($plan))->toBe(['FormDataNoteNoLongerRequired'])
        ->and($plan->changes[0]->verb)->toBe("#[MadeRequestFieldOptional(schema: FormData::class, field: 'note')]")
        ->and($plan->changes[0]->description)->toBe('`FormData` no longer requires `note`.')
        ->and($plan->gaps)->toBe([]);
});

it('refuses a request field that BECAME required, because no verb says it honestly', function (): void {
    // `SchemaPolarity::memberPresence()` records the asymmetry: `required` arriving narrows a request.
    // The older document would have to be looser than the wire, and nothing can check that.
    $plan = scaffoldPlan(
        scaffoldDocument(['note' => ['type' => 'string']]),
        scaffoldDocument(['note' => ['type' => 'string']], ['note']),
        FormData::class.'#request',
    );

    expect(scaffoldClasses($plan))->toBe([])
        ->and($plan->gaps)->toContain('A request field that BECAME required has no honest verb — the older document would have to be looser than the wire, which nothing can check — so it was not written.');
});

/*
 * The rename is the one difference the scaffolder writes for BOTH facets, because it is the one that is
 * really one sentence read in two directions: the field is published under both names either way, and
 * only what it is called moved. The class name carries the facet — one class can be published on both
 * sides, and two changes of one name would be one file overwriting the other.
 */
it('declares a renamed request field, marked as the request half', function (): void {
    $plan = scaffoldPlan(
        scaffoldDocument(['note' => ['type' => 'string']]),
        scaffoldDocument(['memo' => ['type' => 'string']]),
        FormData::class.'#request',
    );

    expect(scaffoldClasses($plan))->toBe(['FormDataRequestMemoReplacesNote'])
        ->and($plan->changes[0]->verb)->toBe("#[RenamedRequestField(schema: FormData::class, from: 'note', to: 'memo')]")
        ->and($plan->changes[0]->description)->toBe('`FormData` accepts `memo` where it accepted `note`.')
        ->and($plan->gaps)->toBe([]);
});

it('refuses a removed request field', function (): void {
    $plan = scaffoldPlan(
        scaffoldDocument(['note' => ['type' => 'string'], 'gone' => ['type' => 'integer']]),
        scaffoldDocument(['note' => ['type' => 'string']]),
        FormData::class.'#request',
    );

    expect(scaffoldClasses($plan))->toBe([])
        ->and($plan->gaps)->toContain('The vocabulary has no verb for a removed REQUEST field: a request body that stopped accepting a field is not something an older document can be given back honestly.');
});

it('leaves a shape no verb can name alone, and says which', function (mixed $source): void {
    // A paginated envelope is a facet of a class rather than the class, and a class that pinned
    // `#[SchemaId]` publishes under an identity that is not a class name at all. Either way there is
    // nothing a verb's `schema:` could be written as.
    $plan = scaffoldPlan(
        scaffoldDocument(['id' => ['type' => 'integer'], 'gone' => ['type' => 'string']]),
        scaffoldDocument(['id' => ['type' => 'integer']]),
        is_string($source) ? $source : null,
    );

    expect(scaffoldClasses($plan))->toBe([])->and($plan->gaps)->toHaveCount(1);
})->with([
    'a paginated envelope' => [FormData::class.'#page'],
    'a pinned #[SchemaId]' => ['legacy-form'],
]);

it('says a schema no class produces cannot be named by a verb', function (): void {
    $plan = scaffoldPlan(
        scaffoldDocument(['id' => ['type' => 'integer'], 'gone' => ['type' => 'string']]),
        scaffoldDocument(['id' => ['type' => 'integer']]),
        null,
    );

    expect(scaffoldClasses($plan))->toBe([])
        ->and($plan->gaps[0])->toContain('No class produces `FormData`');
});

it('refuses to guess a rename it cannot tell apart', function (): void {
    // Two fields of one shape went, one of that shape arrived: nothing here can say which became which,
    // and a guess renames the wrong field in every document derived from this version.
    $plan = scaffoldPlan(
        scaffoldDocument(['alpha' => ['type' => 'string'], 'beta' => ['type' => 'string']]),
        scaffoldDocument(['gamma' => ['type' => 'string']]),
    );

    // Named field by field, because that is what the author has to go and look at.
    expect(scaffoldClasses($plan))->toBe([])
        ->and($plan->gaps)->toContain('`FormData` lost `alpha` and gained a field with the same shape, and nothing here can tell which — declare the rename or the removal yourself.')
        ->and($plan->gaps)->toContain('`FormData` lost `beta` and gained a field with the same shape, and nothing here can tell which — declare the rename or the removal yourself.');
});

it('reads a removal rather than a rename when the shapes differ', function (): void {
    $plan = scaffoldPlan(
        scaffoldDocument(['gone' => ['type' => 'string']]),
        scaffoldDocument(['fresh' => ['type' => 'integer']]),
    );

    expect(scaffoldClasses($plan))->toBe(['FormDataLostGone'])
        ->and($plan->changes[0]->verb)->toContain("type: 'string'")
        ->and($plan->gaps)->toContain('No verb declares a field a version ADDED: older versions simply do not publish it, which is what their documents already say.');
});

it('pairs a rename only when one field of that shape went and one arrived', function (): void {
    $plan = scaffoldPlan(
        scaffoldDocument(['name' => ['type' => 'string']], ['name']),
        scaffoldDocument(['title' => ['type' => 'string']], ['title']),
    );

    expect(scaffoldClasses($plan))->toBe(['FormDataTitleReplacesName'])
        ->and($plan->gaps)->toBe([]);
});

it('writes a class whose short name collides with the verb’s out in full', function (): void {
    // A file that imported two `RenamedResponseField`s is a compile error, so it would never load and
    // the change would never apply — silently. Absurd as a class name and cheap as a guard.
    $plan = scaffoldPlan(
        scaffoldDocument(['name' => ['type' => 'string']]),
        scaffoldDocument(['title' => ['type' => 'string']]),
        RenamedResponseField::class,
    );

    expect($plan->changes[0]->verb)
        ->toBe("#[RenamedResponseField(schema: \\Docuccino\\Attributes\\Versioning\\RenamedResponseField::class, from: 'name', to: 'title')]")
        ->and($plan->changes[0]->imports)->toBe([RenamedResponseField::class]);
});

it('counts a kind of difference rather than repeating its sentence', function (): void {
    // A diff over a real release names hundreds of differences no verb declares, and a reader who
    // scrolls reads none of them.
    $plan = scaffoldPlan(
        scaffoldDocument(['a' => ['type' => 'string'], 'b' => ['type' => 'string']]),
        scaffoldDocument(['a' => ['type' => 'integer'], 'b' => ['type' => 'integer']]),
    );

    expect(scaffoldClasses($plan))->toBe([])
        ->and($plan->gaps)->toHaveCount(1)
        ->and($plan->gaps[0])->toContain('(×2)')
        ->and($plan->gaps[0])->toContain('no version-change verb declares this');
});

it('leaves a required entry that came or went with its property to the removal verb', function (): void {
    // Otherwise one field gets two verbs saying different things about it: the removal already declares
    // that the older versions promised it.
    $plan = scaffoldPlan(
        scaffoldDocument(['id' => ['type' => 'integer'], 'gone' => ['type' => 'string']], ['id', 'gone']),
        scaffoldDocument(['id' => ['type' => 'integer']], ['id']),
    );

    expect(scaffoldClasses($plan))->toBe(['FormDataLostGone'])
        ->and($plan->changes[0]->verb)->toContain('required: true');
});

/**
 * A document publishing `FormData` for the operations `$paths` and `$webhooks` describe, each mapped to
 * the schema its response carries — `scaffoldRef()` for the shared component, an array for a copy of its
 * own.
 *
 * @param  array<string, mixed>  $properties
 * @param  array<string, array<string, mixed>>  $paths  path => the schema its GET publishes
 * @param  array<string, array<string, mixed>>  $webhooks  name => the schema its POST publishes
 */
function scaffoldOperationDocument(array $properties, array $paths, array $webhooks = []): UirDocument
{
    $operation = static fn (string $method, array $schema): array => [$method => [
        'responses' => ['200' => ['content' => ['application/json' => ['schema' => $schema]]]],
    ]];

    return UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms API', 'version' => '2026-09-01'],
        'paths' => array_map(static fn (array $schema): array => $operation('get', $schema), $paths),
        'webhooks' => array_map(static fn (array $schema): array => $operation('post', $schema), $webhooks),
        'components' => ['schemas' => ['FormData' => [
            'x-docuccino' => ['id' => scaffoldSchemaId()],
            'type' => 'object',
            'properties' => $properties,
        ]]],
    ]);
}

/** @return array<string, mixed> */
function scaffoldRef(): array
{
    return ['$ref' => '#/components/schemas/FormData'];
}

/**
 * The copy an operation had of its own: today's shape, under no identity the head document minted. This
 * is the application having forked, and the only thing that owes an `#[AppliesTo]` at all.
 *
 * @return array<string, mixed>
 */
function scaffoldOwnCopy(): array
{
    return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string']]];
}

it('narrows a change to the operations that changed when the rest had a copy of their own', function (): void {
    // The one case a scope is owed: `/b` published today's shape in the older version already, because
    // it pointed at something else then, so only `/a` must be given the older one.
    $plan = scaffoldPlan(
        scaffoldOperationDocument(['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], ['/a' => scaffoldRef(), '/b' => scaffoldOwnCopy()]),
        scaffoldOperationDocument(['id' => ['type' => 'integer'], 'title' => ['type' => 'string']], ['/a' => scaffoldRef(), '/b' => scaffoldRef()]),
    );

    expect(scaffoldClasses($plan))->toBe(['FormDataTitleReplacesName'])
        ->and($plan->changes[0]->scope)->toBe(["#[AppliesTo(operation: 'GET /a')]"])
        ->and($plan->changes[0]->imports)->toContain(AppliesTo::class);
});

it('writes nothing rather than a scope that would match more operations than it means', function (array $paths, array $webhooks, string $gap): void {
    // An unscoped change here would rewrite operations the application never changed, so the refusal is
    // the honest answer: an incomplete version the author is TOLD about costs them less than a complete
    // one that lies.
    $plan = scaffoldPlan(
        scaffoldOperationDocument(['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], $paths[0], $webhooks[0]),
        scaffoldOperationDocument(['id' => ['type' => 'integer'], 'title' => ['type' => 'string']], $paths[1], $webhooks[1]),
    );

    expect(scaffoldClasses($plan))->toBe([])->and($plan->gaps)->toContain($gap);
})->with([
    'a path a wildcard lives inside' => [
        [['/a*' => scaffoldRef(), '/b' => scaffoldOwnCopy()], ['/a*' => scaffoldRef(), '/b' => scaffoldRef()]],
        [[], []],
        '`FormData` changed for some of the operations that publish it and not others, and "GET /a*" cannot be spelled as a selector without matching more than itself, so nothing was written for it.',
    ],
    'a webhook that goes by no name at all' => [
        [['/b' => scaffoldOwnCopy()], ['/b' => scaffoldRef()]],
        [['formSaved' => scaffoldRef()], ['formSaved' => scaffoldRef()]],
        '`FormData` changed for some of the operations that publish it and not others, and one of them goes by no name a scope can spell, so nothing was written for it.',
    ],
]);

/*
 * Parameters, which none of the component-keyed machinery above reaches: a parameter is flattened onto
 * one operation under an identity that is a function of the operation, the location and the NAME. So the
 * pairing is operation-local, and the evidence is the same one a property rename runs on — identical
 * published shape, unique in both directions.
 */

/**
 * Two operations, each declaring the parameters given, over one document — enough for the differ to
 * pair by identity and for the scaffolder to read a rename out of the pairing.
 *
 * @param  array<string, list<array{0: string, 1: string}>>  $operations  signature-ish key => [in, name] pairs
 */
function scaffoldParameterDocument(array $operations): UirDocument
{
    $paths = [];

    foreach ($operations as $path => $parameters) {
        $paths['/api/'.$path] = ['get' => [
            'x-docuccino' => ['id' => 'op:v1:'.$path],
            'responses' => ['200' => ['description' => 'OK']],
            'parameters' => array_map(static fn (array $parameter): array => [
                'x-docuccino' => ['id' => 'par:v1:'.$path.'-'.$parameter[0].'-'.$parameter[1]],
                'name' => $parameter[1],
                'in' => $parameter[0],
                'required' => false,
                'schema' => ['type' => 'string'],
            ], $parameters),
        ]];
    }

    return UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms API', 'version' => '2026-09-01'],
        'paths' => $paths,
    ]);
}

it('declares a renamed query parameter, naming no class at all', function (): void {
    $plan = scaffoldPlan(
        scaffoldParameterDocument(['forms' => [['query', 'q']]]),
        scaffoldParameterDocument(['forms' => [['query', 'search']]]),
    );

    expect(scaffoldClasses($plan))->toBe(['QuerySearchReplacesQ'])
        ->and($plan->changes[0]->verb)->toBe("#[RenamedParameter(in: 'query', from: 'q', to: 'search')]")
        ->and($plan->changes[0]->description)->toBe('The query parameter `search` was called `q`.')
        ->and($plan->changes[0]->imports)->toBe([RenamedParameter::class])
        // No class, so nothing for the placement rule to read a module off.
        ->and($plan->changes[0]->schema)->toBe('')
        ->and($plan->changes[0]->scope)->toBe([])
        ->and($plan->gaps)->toBe([]);
});

/*
 * The subset rule on the axis a parameter has: the base is every operation the HEAD declares the
 * parameter for, and a scope is written only where the rename was observed on strictly fewer of them.
 * Unlike the schema side there is nothing to fork — the scope is a plain filter over operations.
 */
it('scopes a parameter rename to the operations the diff shows it on', function (): void {
    $plan = scaffoldPlan(
        scaffoldParameterDocument(['forms' => [['query', 'q']], 'entries' => [['query', 'search']]]),
        scaffoldParameterDocument(['forms' => [['query', 'search']], 'entries' => [['query', 'search']]]),
    );

    expect(scaffoldClasses($plan))->toBe(['QuerySearchReplacesQ'])
        ->and($plan->changes[0]->scope)->toBe(["#[AppliesTo(operation: 'GET /api/forms')]"])
        ->and($plan->changes[0]->imports)->toContain(AppliesTo::class);
});

it('leaves a parameter rename unscoped when every operation that declares it was renamed', function (): void {
    $plan = scaffoldPlan(
        scaffoldParameterDocument(['forms' => [['query', 'q']], 'entries' => [['query', 'q']]]),
        scaffoldParameterDocument(['forms' => [['query', 'search']], 'entries' => [['query', 'search']]]),
    );

    expect(scaffoldClasses($plan))->toBe(['QuerySearchReplacesQ'])
        ->and($plan->changes[0]->scope)->toBe([]);
});

/*
 * The pairing is per LOCATION as well as per operation: `page` in the query and `page` in the path are
 * two parameters, and a rename read across the two would move one the author never named.
 */
it('never pairs a parameter that went in one location with one that arrived in another', function (): void {
    $plan = scaffoldPlan(
        scaffoldParameterDocument(['forms' => [['query', 'q']]]),
        scaffoldParameterDocument(['forms' => [['header', 'search']]]),
    );

    expect(scaffoldClasses($plan))->toBe([])
        ->and($plan->gaps)->toContain('The query parameter `q` went and nothing that arrived beside it wears the same shape, so no rename could be read out of it — and no verb declares a parameter a version simply stopped accepting.')
        ->and($plan->gaps)->toContain('No verb declares a parameter a version ADDED: older versions simply do not accept it, which is what their documents already say.');
});

/*
 * And the ambiguous case is a different sentence from the unpaired one, because it is a different fact.
 * Two that went and one that arrived: the one arrival wears the shape of BOTH departures, so nothing
 * here names a single pair. An author told "nothing that arrived beside it wears the same shape" —
 * while looking at a diff where two things did, which is exactly why nothing was written — would go
 * looking for a shape difference that is not there.
 */
it('says which parameter differences it could read no rename out of', function (): void {
    $plan = scaffoldPlan(
        scaffoldParameterDocument(['forms' => [['query', 'q'], ['query', 'term']]]),
        scaffoldParameterDocument(['forms' => [['query', 'search']]]),
    );

    expect(scaffoldClasses($plan))->toBe([])
        ->and($plan->gaps)->toContain('The query parameter `q` went and more than one that arrived beside it wears the same shape, so nothing here can tell which — declare the rename yourself, or leave it: no verb declares a parameter a version simply stopped accepting.')
        ->and($plan->gaps)->toContain('The query parameter `term` went and more than one that arrived beside it wears the same shape, so nothing here can tell which — declare the rename yourself, or leave it: no verb declares a parameter a version simply stopped accepting.')
        // And not the sentence for a departure nothing arrived beside, which is what it used to say.
        ->and(implode("\n", $plan->gaps))->not->toContain('nothing that arrived beside it wears the same shape');
});

/**
 * The source-of-truth guard `PARAMETER_MOVES` owes. It is a hand-maintained set, and a
 * hand-maintained set is silent when it goes short: a differ that started minting a fourth
 * arrival-shaped code would be read as a difference the vocabulary does not express and demoted to a
 * gap sentence, with the whole suite green.
 *
 * So the differ's own source is the list, and the assertion is the UNION — every parameter code it
 * mints is either classified by the table or carries a row here saying it deliberately owes no answer.
 * A guard over only the classified half is silent about exactly the codes that go missing, and one
 * over only the exclusions is silent about the rest.
 */
it('classifies every parameter code the differ mints, or says which of them owe no answer', function (): void {
    // The codes no rename can be assembled out of, each with what makes that true. A new entry here is
    // a claim, so it needs a sentence like the ones above it.
    $owesNoAnswer = [
        'parameter.became-required' => 'a required-ness move on a parameter that stayed where it was, and the vocabulary has no verb for one',
        'parameter.became-optional' => 'the same fact the other way round, and the same absence of a verb',
        'parameter.description-changed' => 'prose about a parameter neither side moved, so there is no departure to pair an arrival with',
    ];

    $source = (string) file_get_contents(dirname(__DIR__, 3).'/core/src/Diff/DocumentDiffer.php');
    preg_match_all("/'(parameter\\.[a-z-]+)'/", $source, $matches);

    $minted = array_values(array_unique($matches[1]));
    sort($minted, SORT_STRING);

    $constant = (new ReflectionClass(ChangeScaffolder::class))->getReflectionConstant('PARAMETER_MOVES');

    /** @var array<string, string> $table */
    $table = $constant === false ? [] : $constant->getValue();

    $union = array_values(array_unique([...array_keys($table), ...array_keys($owesNoAnswer)]));
    sort($union, SORT_STRING);

    // A scan that matched nothing has to fail rather than pass, so the plausible minimum is stated
    // beside the real assertion: the differ mints six parameter codes today.
    expect($source)->not->toBe('')
        ->and(count($minted))->toBeGreaterThanOrEqual(6)
        ->and($minted)->toBe($union)
        // And no code is answered both ways, which would make the union hold while saying two things.
        ->and(array_intersect(array_keys($table), array_keys($owesNoAnswer)))->toBe([]);
});
