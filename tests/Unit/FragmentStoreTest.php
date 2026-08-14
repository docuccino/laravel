<?php

declare(strict_types=1);

use Docuccino\Laravel\Pipeline\FragmentStore;

/**
 * The fragment store's clear path (`docuccino:clear --fragments`): entries and abandoned temp files
 * go, the directory and its `.gitignore` stay, and a store that was never written is not an error.
 */
it('clears the entries and any abandoned temp file, keeping the directory', function (): void {
    $dir = sys_get_temp_dir().'/docuccino-store-'.uniqid('', true);
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/.gitignore', "*\n!.gitignore\n");
    file_put_contents($dir.'/a.json', '{}');
    file_put_contents($dir.'/b.json', '{}');
    file_put_contents($dir.'/b.json.123.ff.tmp', '{}');

    $cleared = (new FragmentStore(true, $dir))->clear();

    expect($cleared)->toBe(2)
        ->and(glob($dir.'/*.json') ?: [])->toBe([])
        ->and(glob($dir.'/*.tmp') ?: [])->toBe([])
        ->and(is_file($dir.'/.gitignore'))->toBeTrue();

    @unlink($dir.'/.gitignore');
    @rmdir($dir);
});

it('clears nothing when the store was never written', function (): void {
    $store = new FragmentStore(false, sys_get_temp_dir().'/docuccino-store-'.uniqid('', true));

    expect($store->clear())->toBe(0);
});
