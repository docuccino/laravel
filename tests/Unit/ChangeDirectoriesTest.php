<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Versioning\ChangeDirectories;

/*
 * The one reading of `api_version.changes`, on a tree built for the purpose.
 *
 * Two properties the feature tests cannot reach from a fixture directory: what a glob does when one of
 * its matches is a symlink pointing out of the application, and that a literal entry is still handed
 * back when it does not exist yet — which is what lets `docuccino:watch` notice the directory somebody
 * is about to create.
 */

/** A base path with `app/Api/Versions` and two modules under it. */
function changeDirectoriesTree(): string
{
    $base = rtrim(sys_get_temp_dir(), '/').'/docuccino-change-dirs-'.getmypid();

    foreach (['app/Api/Versions', 'modules/Alpha/Api/Versions', 'modules/Zebra/Api/Versions'] as $directory) {
        if (! is_dir($base.'/'.$directory)) {
            mkdir($base.'/'.$directory, 0755, true);
        }
    }

    return $base;
}

/**
 * @param  list<string>  $changes
 * @return array{0: list<string>, 1: list<string>, 2: array<string, string>} directories, diagnostic
 *                                                                           codes, module roots
 */
function resolvedChangeDirectories(string $base, array $changes): array
{
    [$directories, $diagnostics, $modules] = ChangeDirectories::resolve($base, new DocumentConfig(
        key: 'v',
        info: [],
        raw: ['api_version' => ['changes' => $changes]],
    ));

    return [$directories, array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, $diagnostics), $modules];
}

it('expands a glob to every module it matches, sorted, and keeps the entries in configured order', function (): void {
    $base = changeDirectoriesTree();

    [$directories, $codes] = resolvedChangeDirectories($base, ['app/Api/Versions', 'modules/*/Api/Versions']);

    expect($codes)->toBe([])->and($directories)->toBe([
        $base.'/app/Api/Versions',
        $base.'/modules/Alpha/Api/Versions',
        $base.'/modules/Zebra/Api/Versions',
    ]);
});

it('hands back a literal directory that does not exist, and says so', function (): void {
    // Both halves matter: the path is what a watch session roots on so a change written later
    // registers, and the diagnostic is what tells the author nothing was read from it today.
    $base = changeDirectoriesTree();

    [$directories, $codes] = resolvedChangeDirectories($base, ['app/Api/Later']);

    expect($directories)->toBe([$base.'/app/Api/Later'])
        ->and($codes)->toBe(['versioning.dir-missing']);
});

it('refuses a globbed match that leaves the application through a symlink', function (): void {
    // The hole a glob would otherwise open: the ENTRY is inside the application, and `*` matches a link
    // whose target is not. Confinement is re-checked on what the glob found, not on what was configured.
    $base = changeDirectoriesTree();
    $outside = rtrim(sys_get_temp_dir(), '/').'/docuccino-change-dirs-outside-'.getmypid();

    if (! is_dir($outside.'/Api/Versions')) {
        mkdir($outside.'/Api/Versions', 0755, true);
    }

    $link = $base.'/modules/Escape';
    if (! is_link($link)) {
        symlink($outside, $link);
    }

    [$directories, $codes] = resolvedChangeDirectories($base, ['modules/*/Api/Versions']);

    expect($directories)->toBe([
        $base.'/modules/Alpha/Api/Versions',
        $base.'/modules/Zebra/Api/Versions',
    ])->and($codes)->toBe(['versioning.dir-escapes-base']);

    unlink($link);
    foreach (['/Api/Versions', '/Api', ''] as $leaf) {
        rmdir($outside.$leaf);
    }
});

it('takes an absolute entry at its word, because naming one is deliberate', function (): void {
    $base = changeDirectoriesTree();

    [$directories, $codes] = resolvedChangeDirectories($base, [$base.'/modules/*/Api/Versions']);

    expect($codes)->toBe([])->and($directories)->toBe([
        $base.'/modules/Alpha/Api/Versions',
        $base.'/modules/Zebra/Api/Versions',
    ]);
});

it('names one directory once, however many entries reach it', function (): void {
    $base = changeDirectoriesTree();

    [$directories, $codes] = resolvedChangeDirectories($base, [
        'modules/Alpha/Api/Versions',
        'modules/*/Api/Versions',
    ]);

    expect($codes)->toBe([])->and($directories)->toBe([
        $base.'/modules/Alpha/Api/Versions',
        $base.'/modules/Zebra/Api/Versions',
    ]);
});

/*
 * The module root behind a match — the third answer, and the one the scaffolder places a change with.
 * Read here rather than derived from a resolved directory, because a wildcard is the only thing that
 * says where an author's boundary is and this is where the wildcard was expanded.
 */
it('names the module root a globbed entry declares, and none for a literal one', function (): void {
    $base = changeDirectoriesTree();

    [, $codes, $modules] = resolvedChangeDirectories($base, ['app/Api/Versions', 'modules/*/Api/Versions']);

    expect($codes)->toBe([])->and($modules)->toBe([
        // The wildcard's own segment, not the whole match and not the prefix before it: `modules/` on
        // its own holds every module and so tells one from another about as well as `.` does.
        $base.'/modules/Alpha/Api/Versions' => $base.'/modules/Alpha',
        $base.'/modules/Zebra/Api/Versions' => $base.'/modules/Zebra',
    ]);
});

it('reads the boundary off the entry as written, wherever the wildcard sits', function (string $entry, string $root): void {
    $base = changeDirectoriesTree();

    [, , $modules] = resolvedChangeDirectories($base, [$entry]);

    expect($modules[$base.'/modules/Alpha/Api/Versions'] ?? null)->toBe($root === '' ? null : $base.'/'.$root);
})->with([
    'a wildcard naming the module' => ['modules/*/Api/Versions', 'modules/Alpha'],
    'a wildcard at the top' => ['*/Alpha/Api/Versions', 'modules'],
    'the FIRST of two wildcards' => ['modules/*/*/Versions', 'modules/Alpha'],
    'a wildcard below the module' => ['modules/Alpha/*/Versions', 'modules/Alpha/Api'],
]);

it('names a module root for an absolute entry too, since the match is absolute either way', function (): void {
    $base = changeDirectoriesTree();

    [, , $modules] = resolvedChangeDirectories($base, [$base.'/modules/*/Api/Versions']);

    expect($modules)->toBe([
        $base.'/modules/Alpha/Api/Versions' => $base.'/modules/Alpha',
        $base.'/modules/Zebra/Api/Versions' => $base.'/modules/Zebra',
    ]);
});

it('lets the first entry to claim a directory keep its module root', function (): void {
    // Two patterns reaching one tree answer in CONFIGURED order rather than in whichever expanded last,
    // so the destination a change gets cannot move when an entry is appended.
    $base = changeDirectoriesTree();

    [, , $modules] = resolvedChangeDirectories($base, ['modules/Alpha/*/Versions', 'modules/*/Api/Versions']);

    expect($modules[$base.'/modules/Alpha/Api/Versions'])->toBe($base.'/modules/Alpha/Api');
});
