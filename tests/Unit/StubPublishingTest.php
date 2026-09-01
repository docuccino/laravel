<?php

declare(strict_types=1);

use Docuccino\Attributes\Versioning\RemovedResponseField;
use Docuccino\Laravel\DocuccinoServiceProvider;
use Docuccino\Laravel\Versioning\Scaffold\ChangeStub;
use Docuccino\Laravel\Versioning\Scaffold\ScaffoldedChange;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Data\FormData;

/*
 * The stub is publishable, under a tag, from the framework's own mechanism.
 *
 * Asserted rather than assumed because "run `vendor:publish --tag=docuccino-stubs`" is printed in the
 * reference: a tag nothing registers is a documented command that copies nothing, and the author reads
 * that as "there is no stub to edit".
 */

/**
 * Every `{{ … }}` a stub carries, in either spelling.
 *
 * @return list<string>
 */
function stubPlaceholders(string $path): array
{
    preg_match_all('/\{\{\s*([a-z]+)\s*\}\}/', (string) file_get_contents($path), $matches);

    $names = array_values(array_unique($matches[1]));
    sort($names, SORT_STRING);

    return $names;
}

it('publishes the packaged stub under the docuccino-stubs tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(DocuccinoServiceProvider::class, 'docuccino-stubs');

    expect($paths)->toBe([ChangeStub::packaged() => base_path('stubs/docuccino/version-change.stub')])
        ->and(is_file(ChangeStub::packaged()))->toBeTrue();
});

it('publishes it where the generator looks for it', function (): void {
    // The two halves have to agree: publishing to a path the resolver does not read would silently keep
    // using the packaged template while the author edited a file nothing reads.
    $paths = ServiceProvider::pathsToPublish(DocuccinoServiceProvider::class, 'docuccino-stubs');
    $destination = (string) array_values($paths)[0];

    expect($destination)->toBe(base_path(ChangeStub::PUBLISHED_DIRECTORY.'/'.ChangeStub::NAME))
        ->and((new ChangeStub(base_path()))->published())->toBe(is_file($destination));
});

it('fills every placeholder the packaged stub carries', function (): void {
    // Read off the stub rather than listed here: a placeholder the renderer does not know would ship as
    // literal `{{ … }}` in somebody's class, and a hand-kept list is exactly what would not notice.
    $rendered = (new ChangeStub(base_path()))->render(
        new ScaffoldedChange('FormDataLostSubtotal', FormData::class, '2026-09-01', 'Gone.', "#[RemovedResponseField(schema: FormData::class, field: 'subtotal')]", [RemovedResponseField::class, FormData::class]),
        'App\\Api\\Versions',
    );

    expect(stubPlaceholders(ChangeStub::packaged()))->not->toBeEmpty()
        ->and($rendered)->toBeString()
        ->and($rendered)->not->toContain('{{')
        ->and($rendered)->toContain('namespace App\\Api\\Versions;')
        ->and($rendered)->toContain('use Docuccino\\Attributes\\Versioning\\ApiVersionChange;')
        ->and($rendered)->toContain('final class FormDataLostSubtotal {}');
});

it('escapes a value that would otherwise close the string it is written into', function (): void {
    // Field names and descriptions come off an artifact nobody validated. A stray quote is a syntax
    // error in the written file, and a class that does not compile is a change nothing ever applies.
    $rendered = (new ChangeStub(base_path()))->render(
        new ScaffoldedChange('FormDataLostIt', FormData::class, '2026-09-01', "It's a \\ mess.", '#[X]', []),
        'App\\Api\\Versions',
    );

    expect($rendered)->toBeString()
        ->and($rendered)->toContain("description: 'It\\'s a \\\\ mess.',");
});
