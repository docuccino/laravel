<?php

declare(strict_types=1);

use Docuccino\Laravel\Tests\Support\BuildSettingSites;

/**
 * The seam a test configures a build through, held to being the only one.
 *
 * `BuildSettings` writes YAML and hands it to the reader the product uses. The path this file closes
 * is the other one: the framework's config repository, which a build stopped reading, and which
 * accepts any key silently — so a test that sets a build setting there asserts the shipped default
 * and passes for the wrong reason.
 */
it('sets a key the framework does not read only where that is the subject', function (): void {
    $offenders = array_keys(BuildSettingSites::pastTheReader());

    expect($offenders)->toEqualCanonicalizing(array_keys(BuildSettingSites::DELIBERATE));
});

it('skips only the two files that write the call out on purpose', function (): void {
    // Skipped by NAME, so a rename would silently stop skipping — or silently start skipping nothing.
    foreach (BuildSettingSites::WRITTEN_OUT as $file) {
        expect(BuildSettingSites::root().'/'.$file)->toBeFile();
    }
});

it('lists no file that has stopped planting one', function (): void {
    // The allow-list is hand-maintained, so it is read against the sources rather than trusted: an
    // entry whose file no longer sets such a key would go on excusing whatever the file does next.
    $found = BuildSettingSites::pastTheReader();

    foreach (array_keys(BuildSettingSites::DELIBERATE) as $file) {
        expect($found)->toHaveKey($file);
        expect($found[$file])->not->toBe([]);
    }
});

it('still recognises the shape it scans for', function (): void {
    // A scan that matched nothing would report no offenders and read as a pass. The suite sets plenty
    // of the framework's own keys, so the floor is well under today's count and still far from zero.
    $sites = BuildSettingSites::every();

    expect(count($sites))->toBeGreaterThan(40)
        ->and($sites)->toContain('enabled')
        ->and($sites)->toContain('documents.default.viewer.gate');
});

it('refuses the call it exists to refuse, and passes the one it does not', function (): void {
    // The guard executed rather than asserted: this is the code it has to catch, written out.
    $offending = <<<'PHP'
        config()->set('docuccino.lint.tags.enabled', true);
        PHP;

    $sound = <<<'PHP'
        config()->set('docuccino.enabled', false);
        config()->set('docuccino.cache.store', 'array');
        config()->set('docuccino.documents.default.viewer.gate', 'viewApiDocs');
        config(['docuccino.documents.admin.viewer' => ['route' => '/docs/admin']]);
        PHP;

    // And a key assembled rather than written: the line does not say which setting it names, so it is
    // refused with the rest instead of read as though it did.
    $assembled = <<<'PHP'
        config()->set('docuccino.documents.default.'.$key, []);
        PHP;

    expect(BuildSettingSites::offending($offending))->toBe(['lint.tags.enabled'])
        ->and(BuildSettingSites::offending($sound))->toBe([])
        ->and(BuildSettingSites::offending($assembled))->toBe(['documents.default.'])
        // Both spellings are read, so neither is a way round the guard.
        ->and(BuildSettingSites::offending("config(['docuccino.lint.tags.enabled' => true]);"))->toBe(['lint.tags.enabled']);
});
