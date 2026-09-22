<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * `#[AddedEnumValue]` and `#[RemovedEnumValue]`, against real builds of the workbench.
 *
 * The pair moves a VALUE rather than a key, and the whole difficulty is what travels beside one: an
 * enum's member names and its per-value prose are published parallel to `enum` and applied by index, so
 * most of this file is about the three staying in step. The workbench body carries both decoration
 * shapes on purpose — `FormVisibility` describes every value and so publishes the map, `WidgetPriority`
 * describes some and so publishes only the positional array.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/visible-forms', [VersionedFormController::class, 'visible']);
});

/**
 * One published set as the document publishes it with the changes in `$dir` applied.
 *
 * @return array<string, mixed>
 */
function versionedSet(string $dir, string $component = 'FormVisibility'): array
{
    versioningDiagnostics($dir, route: 'api/visible-forms');

    /** @var array<string, mixed> $schema */
    $schema = generateDocument(key: 'v')->document->toArray()['components']['schemas'][$component];

    return $schema;
}

/**
 * The reports the changes in `$dir` raise, by code.
 *
 * @return list<string>
 */
function versionedSetCodes(string $dir): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code,
        versioningDiagnostics($dir, route: 'api/visible-forms'),
    );
}

it('leaves out the value the version added', function (): void {
    expect(versionedSet('tests/Fixtures/Versioning/EnumValueAdded')['enum'])->toBe(['public', 'internal']);
});

/*
 * The half that makes it more than an array edit. Every decoration member is parallel to `enum` and
 * applied by INDEX, so a value spliced out while they kept their length would hand every member past it
 * the previous one's name and prose in a generated client.
 */
it('takes the value out of everything published parallel to it', function (): void {
    $set = versionedSet('tests/Fixtures/Versioning/EnumValueAdded');

    expect($set['x-enum-varnames'])->toBe(['Public', 'Internal'])
        ->and($set['x-enumNames'])->toBe(['Public', 'Internal'])
        ->and($set['x-enum-descriptions'])->toBe(['Anyone with the link.', 'Only signed-in members of the workspace.'])
        ->and($set['x-enumDescriptions'])->toBe([
            'public' => 'Anyone with the link.',
            'internal' => 'Only signed-in members of the workspace.',
        ]);
});

it('reads an int-backed value as the number the wire carries', function (): void {
    $set = versionedSet('tests/Fixtures/Versioning/EnumValueAddedInt', 'WidgetPriority');

    expect($set['enum'])->toBe([1, 5])
        ->and($set['x-enum-varnames'])->toBe(['Low', 'Normal']);
});

/*
 * The positional array keeps its empty-string gaps: consumers apply it by index, so a set where only
 * some values are described publishes a full-length array rather than a short one.
 */
it('keeps the gaps in a set only partly described', function (): void {
    $set = versionedSet('tests/Fixtures/Versioning/EnumValueAddedInt', 'WidgetPriority');

    expect($set['x-enum-descriptions'])->toBe(['Handled when idle.', ''])
        ->and($set)->not->toHaveKey('x-enumDescriptions');
});

it('lists the value the version took away, with what older clients called it', function (): void {
    $set = versionedSet('tests/Fixtures/Versioning/EnumValueRemoved');

    expect($set['enum'])->toBe(['public', 'internal', 'invited', 'unlisted'])
        ->and($set['x-enum-varnames'])->toBe(['Public', 'Internal', 'Invited', 'Unlisted'])
        ->and($set['x-enumDescriptions']['unlisted'])->toBe('Reachable by link, and left out of every index.');
});

it('applies both directions on one set in the order the rule settles', function (): void {
    // The second verb reads the set the FIRST one left, rather than the one the code publishes.
    $set = versionedSet('tests/Fixtures/Versioning/EnumValuePair');

    expect($set['enum'])->toBe(['public', 'internal', 'unlisted'])
        ->and($set['x-enum-varnames'])->toBe(['Public', 'Internal', 'Unlisted']);
});

/*
 * A value put back with no sentence into a set that described every other one. The map's contract is
 * completeness — a reader hides the values missing from it — so the map goes rather than the set
 * publishing a stale one, and the author is told which declaration did it.
 */
it('drops the description map rather than publish an incomplete one', function (): void {
    $set = versionedSet('tests/Fixtures/Versioning/EnumValueRemovedUndescribed');

    expect($set['enum'])->toBe(['public', 'internal', 'invited', 'hidden'])
        ->and($set)->not->toHaveKey('x-enumDescriptions')
        ->and($set['x-enum-descriptions'])->toBe([
            'Anyone with the link.',
            'Only signed-in members of the workspace.',
            'Only the people it was sent to.',
            '',
        ]);
});

it('says which declaration cost the set its descriptions', function (): void {
    expect(versionedSetCodes('tests/Fixtures/Versioning/EnumValueRemovedUndescribed'))->toBe(['versioning.enum-prose-dropped']);
});

it('mints a member name for a value the declaration did not name one for', function (): void {
    // A pure function of the value, so putting a value back never renames a neighbour.
    expect(versionedSet('tests/Fixtures/Versioning/EnumValueRemovedUndescribed')['x-enum-varnames'])
        ->toBe(['Public', 'Internal', 'Invited', 'Hidden']);
});

it('says so where the set already says what the version would make it say', function (): void {
    expect(versionedSetCodes('tests/Fixtures/Versioning/EnumValueUnchanged'))->toBe(['versioning.change-target-unchanged']);
});

it('says so where the class the declaration names publishes no value set', function (): void {
    expect(versionedSetCodes('tests/Fixtures/Versioning/EnumValueNotASet'))->toBe(['versioning.change-target-missing']);
});

it('says so where the document publishes no such set at all', function (): void {
    expect(versionedSetCodes('tests/Fixtures/Versioning/EnumValueUnresolved'))->toBe(['versioning.schema-unresolved']);
});

it('refuses a value belonging to no set', function (): void {
    expect(versionedSetCodes('tests/Fixtures/Versioning/EnumValueEmpty'))->toBe(['versioning.change-invalid']);
});

it('moves nothing where a change was refused', function (): void {
    // Every refusal above leaves the set exactly as the code publishes it, which is the half a code
    // assertion does not cover.
    expect(versionedSet('tests/Fixtures/Versioning/EnumValueUnchanged')['enum'])->toBe(['public', 'internal', 'invited'])
        ->and(versionedSet('tests/Fixtures/Versioning/EnumValueEmpty')['enum'])->toBe(['public', 'internal', 'invited']);
});

/*
 * The silence controls. Every refusal above asserts a code; without these the whole file would still
 * pass if the applied path started reporting — and `proseLost`'s guard could be inverted, making a
 * correct declaration raise `versioning.enum-prose-dropped`, with nothing going red.
 */
it('says nothing about a declaration that applied cleanly', function (string $dir): void {
    expect(versionedSetCodes($dir))->toBe([]);
})->with([
    'a value the version added' => ['tests/Fixtures/Versioning/EnumValueAdded'],
    'an int-backed value the version added' => ['tests/Fixtures/Versioning/EnumValueAddedInt'],
    'a value the version took away' => ['tests/Fixtures/Versioning/EnumValueRemoved'],
    'both directions on one set' => ['tests/Fixtures/Versioning/EnumValuePair'],
]);

it('grows the positional prose array when a value joins a partly described set', function (): void {
    // The int-backed half of the decoration: no completeness map to lose, and the positional array has
    // to stay full length or every member past the new one takes its neighbour's prose.
    $set = versionedSet('tests/Fixtures/Versioning/EnumValueRemovedInt', 'WidgetPriority');

    expect($set['enum'])->toBe([1, 5, 10, 20])
        ->and($set['x-enum-descriptions'])->toBe(['Handled when idle.', '', 'Jumps the queue.', ''])
        ->and($set['x-enum-varnames'])->toBe(['Low', 'Normal', 'High', 'Urgent'])
        ->and(versionedSetCodes('tests/Fixtures/Versioning/EnumValueRemovedInt'))->toBe([]);
});

it('publishes no member names rather than two members sharing one', function (): void {
    // Generators apply these by index and without a dedupe, so a colliding pair is an identifier
    // collision in somebody's client. The set widens to no names and the build says which declaration.
    $set = versionedSet('tests/Fixtures/Versioning/EnumValueRemovedColliding');

    expect($set['enum'])->toBe(['public', 'internal', 'invited', 'internal-only'])
        ->and($set)->not->toHaveKey('x-enum-varnames')
        ->and($set)->not->toHaveKey('x-enumNames')
        ->and(versionedSetCodes('tests/Fixtures/Versioning/EnumValueRemovedColliding'))
        ->toBe(['versioning.enum-name-contested']);
});
