<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Post;

/**
 * The recovery half, over the REAL engine. A `{listing:title}` parameter is typed from a column an
 * idiomatic Eloquent model never declares as a PHP property — it lives in `$attributes`, documented
 * with a class-level `@property` tag — so the type the parameter ends up with is one PHPStan recovered
 * from the fixture app, not one a stub handed over. The enum row is the honest refusal at the same
 * fidelity: the real engine DOES recover `status`, and a URL segment still cannot carry it.
 *
 * The metadata is re-keyed onto an in-process twin because the reflector reflects the FQCN it is given
 * (casts, key type, traits) and nothing in this process can load a fixture-app class.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('types a bound column from what the real engine recovered for it', function (string $column, ?array $expected): void {
    $real = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Models\\Listing'));
    $twin = new ClassMetadata(Post::class, $real->properties, $real->summary);

    // The row proves nothing if the engine reported no such column in the first place.
    expect(array_map(static fn ($property): string => $property->name, $twin->properties))->toContain($column);

    expect((new EloquentModelReflector)->columnSchemaFor(Post::class, $column, $twin))->toBe($expected);
})->with([
    'a @property string column' => ['title', ['type' => 'string']],
    'a @property int column' => ['id', ['type' => 'integer']],
    'a @property bool column' => ['active', ['type' => 'boolean']],
    // Recovered, and refused: an enum has no single-scalar form a path segment carries.
    'a @property enum column' => ['status', null],
])->group('fixture');
