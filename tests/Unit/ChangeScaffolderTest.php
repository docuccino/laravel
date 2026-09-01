<?php

declare(strict_types=1);

use Docuccino\Attributes\Versioning\AppliesTo;
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

it('declares a request field that stopped being required, which is the one request-side verb there is', function (): void {
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

it('refuses a renamed or removed request field', function (): void {
    $plan = scaffoldPlan(
        scaffoldDocument(['note' => ['type' => 'string'], 'gone' => ['type' => 'integer']]),
        scaffoldDocument(['memo' => ['type' => 'string']]),
        FormData::class.'#request',
    );

    expect(scaffoldClasses($plan))->toBe([])
        ->and($plan->gaps)->toContain('The vocabulary has no verb for a renamed REQUEST field, so the rename in a request body was not written.')
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
