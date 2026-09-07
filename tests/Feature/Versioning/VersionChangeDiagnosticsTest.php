<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * What a build says when a declaration cannot be applied. Each of these fires where the reader can act:
 * every one of them names a change class they wrote and a line they can edit, and none of them fires on
 * a declaration that is simply doing nothing yet.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms', [VersionedFormController::class, 'index']);
});

it('names the directory a document points at and does not have', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/NotThere');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.dir-missing')
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($diagnostics[0]->message)->toContain('tests/Fixtures/Versioning/NotThere');
});

it('refuses a directory outside the application', function (): void {
    $diagnostics = versioningDiagnostics('../../../etc');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.dir-escapes-base');
});

it('says which declaration cannot be applied as it is written', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/Invalid');

    // Three declarations sit there. Two are unusable and each is reported once; the third is an
    // ordinary helper class, which claims to be nothing and is reported as nothing.
    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))
        ->toBe(['versioning.change-invalid', 'versioning.change-invalid', 'versioning.change-invalid']);

    $messages = implode("\n", array_map(static fn (Diagnostic $d): string => $d->message, $diagnostics));

    expect($messages)->toContain('EmptyRename')
        ->toContain('leaves `from:` or `to:` empty')
        ->toContain('renames "title" to itself')
        ->toContain('NoVersion')
        ->toContain('names no version')
        ->and($messages)->not->toContain('NotAChange');
});

/*
 * One author-written word, read once. Every verb normalises its declaration before anything compares or
 * stores it, and this pair is what made that worth stating: read as typed, `from: 'title'` and
 * `to: ' title'` are two different names, so the self-rename refusal let them past — and the change
 * then arrived at the other end as a collision with a field nobody had renamed.
 */
it('refuses a rename whose two ends are one name with room around it', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/PaddedSelfRename');

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))
        ->toBe(['versioning.change-invalid', 'versioning.change-invalid']);

    $messages = implode("\n", array_map(static fn (Diagnostic $d): string => $d->message, $diagnostics));

    // The field verb and the parameter verb both, because the normalisation is one place for every verb.
    expect($messages)->toContain('renames "title" to itself')
        ->toContain('renames "search" to itself');
});

/*
 * And the half that proves the normalisation is applied rather than only compared: a declaration padded
 * on the class AND on both ends of the rename still names what it means. Stored as typed, the class
 * would mint an identity no schema carries and the field would match no property.
 */
it('reads a padded declaration as the names it means', function (): void {
    expect(versioningDiagnostics('tests/Fixtures/Versioning/PaddedRename'))->toBe([]);

    $schema = generateDocument(key: 'v')->document->toArray()['components']['schemas']['FormData'];

    expect(array_keys($schema['properties']))->toBe(['id', 'name', 'publishedAt'])
        ->and($schema['required'])->toBe(['id', 'name']);
});

it('refuses a rename that would collapse two published fields into one', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/Occupied');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.change-invalid')
        ->and($diagnostics[0]->message)->toContain('already publishes a field called "id"');

    // And the document is left honest rather than short a field.
    $schema = generateDocument(key: 'v')->document->toArray()['components']['schemas']['FormData'];
    expect(array_keys($schema['properties']))->toBe(['id', 'title', 'publishedAt']);
});

it('says a declaration has rotted when the field it renames is gone from the code', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/Missing');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.change-target-missing')
        ->and($diagnostics[0]->message)->toContain('renames "headline"')
        ->and($diagnostics[0]->help)->toContain('as it is spelled today');
});

it('says a change names a shape the document does not publish', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/Unresolved');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.schema-unresolved')
        ->and($diagnostics[0]->message)->toContain('App\\Http\\Resources\\NothingResource');
});

/*
 * The foldability guarantee, EXECUTED rather than asserted. PHP refuses a closure in an attribute
 * argument outright; it permits `new`, and the vocabulary's scalar-only parameter types are what close
 * that hole — this object cannot be kept in `string $since`, so the declaration degrades to the
 * existing `attribute.unreadable` diagnostic instead of being believed. What the types do NOT do is
 * stop the argument running: that is the row below.
 */
it('degrades a declaration whose argument cannot be read, rather than reading it', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/Unreadable');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('attribute.unreadable')
        ->and($diagnostics[0]->message)->toContain('#[ApiVersionChange]')
        ->and($diagnostics[0]->message)->toContain('UnreadableArgument');
});

it('says nothing at all when the document names no changes directory', function (): void {
    expect(versioningDiagnostics(null))->toBe([]);
});

it('leaves a change that shipped at or before this version alone', function (): void {
    // The change is `since: 2026-09-01` and names a field that is gone, so a version that applies it
    // reports; a version at or after it must not apply it at all, and so must not report either.
    expect(versioningDiagnostics('tests/Fixtures/Versioning/Missing', '2026-09-01'))->toBe([])
        ->and(versioningDiagnostics('tests/Fixtures/Versioning/Missing', '2027-01-01'))->toBe([]);
});

/*
 * The other half of that guarantee, and the half a docblock is tempted to overstate: PHP EVALUATES a
 * `new` in an attribute argument, so the object's constructor runs before `string $since` gets to
 * refuse it. Which throwable the diagnostic names is the proof — the argument's own, raised while it
 * was being constructed, rather than the TypeError the parameter would have raised on its own.
 */
it('reports the throwable an attribute argument raised while it was being constructed', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/ArgumentEvaluated');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('attribute.unreadable')
        ->and($diagnostics[0]->message)->toContain('EvaluatesArgument')
        ->and($diagnostics[0]->help)->toContain('LogicException')
        ->and($diagnostics[0]->help)->not->toContain('TypeError');
});
