<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\EnumDecoration;
use Docuccino\Laravel\Viewer\RedocViewer;
use Docuccino\Laravel\Viewer\ScalarViewer;

/**
 * What the SHIPPED bundles do with the enum decoration the product mints. The emitting half is
 * covered in core; this is the other half of a driver's contract — a document carrying prose no
 * bundled viewer reads is prose the author's reader never sees, and no markup test notices.
 *
 * Each row was measured against the pinned bundle by rendering the shapes the emitter produces in a
 * browser and reading the DOM: Scalar renders both description spellings and both name spellings,
 * beside the value; Redoc reads the value-keyed map alone, and HIDES any value missing from it,
 * which is why {@see EnumDecoration} withholds a partial map. A `false` is a fact about that
 * version, not an aspiration — a bundle that gains support fails here and the claim gets rewritten
 * rather than drifting.
 */
dataset('viewerDecorationSupport', [
    'scalar' => ['scalar', [
        'x-enumDescriptions' => true,
        'x-enum-descriptions' => true,
        'x-enumNames' => true,
        'x-enum-varnames' => true,
    ]],
    'redoc' => ['redoc', [
        'x-enumDescriptions' => true,
        'x-enum-descriptions' => false,
        'x-enumNames' => false,
        'x-enum-varnames' => false,
    ]],
]);

it('answers for every decoration key the product mints, in every shipped driver', function (string $driver, array $rows): void {
    // The keys the product can mint, taken by RUNNING the decoration over every naming keyword
    // rather than restated — so a newly minted key arrives here without a row and fails, instead
    // of shipping unexamined.
    $schema = ['type' => 'string', 'enum' => ['draft', 'published', 'archived']];
    $descriptions = ['draft' => 'Not advertised.', 'published' => 'Live.', 'archived' => 'Withdrawn.'];

    $minted = [];
    foreach (RepresentationPolicy::ENUM_NAMINGS as $naming) {
        foreach (array_keys(EnumDecoration::apply($schema, $naming, ['Draft', 'Published', 'Archived'], $descriptions)) as $key) {
            if (str_starts_with((string) $key, 'x-')) {
                $minted[(string) $key] = true;
            }
        }
    }

    $minted = array_keys($minted);
    sort($minted);

    $stated = array_map(strval(...), array_keys($rows));
    sort($stated);

    // A plausible minimum, so a decoration that stopped being emitted cannot empty the domain and
    // leave every row vacuously satisfied.
    expect($minted)->toHaveCount(4)
        // The stated rows ARE the domain: a key with no answer cannot pass, and an answer about a
        // key the product no longer mints cannot linger as a claim about nothing.
        ->and($stated)->toBe($minted, "the $driver rows and the minted decoration keys have drifted apart");
})->with('viewerDecorationSupport');

it('holds each shipped bundle to the decoration keys its pinned version reads', function (string $driver, array $rows): void {
    $asset = $driver === 'scalar'
        ? (new ScalarViewer)->assets()['scalar']
        : (new RedocViewer)->assets()['redoc'];

    $bundle = (string) file_get_contents($asset);

    // A plausible minimum, so an emptied or truncated asset fails rather than reporting "reads
    // nothing" as though it were a finding about the renderer.
    expect(strlen($bundle))->toBeGreaterThan(500_000);

    foreach ($rows as $key => $reads) {
        expect(str_contains($bundle, (string) $key))->toBe(
            $reads,
            $reads
                ? "the shipped $driver bundle no longer reads $key — a document carrying it now renders bare values"
                : "the shipped $driver bundle has gained $key: the support table, and the reference page it feeds, are now wrong",
        );
    }
})->with('viewerDecorationSupport');

/**
 * Reading a key is not rendering it. Scalar's own element classes are the nearest in-process
 * evidence that the prose reaches a reader rather than being parsed and dropped.
 */
it('ships a Scalar bundle with somewhere to put the prose it reads', function (): void {
    $bundle = (string) file_get_contents((new ScalarViewer)->assets()['scalar']);

    expect(str_contains($bundle, 'property-enum-value-description'))->toBeTrue()
        ->and(str_contains($bundle, 'property-enum-value-label'))->toBeTrue();
});
