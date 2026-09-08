<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Docuccino\Laravel\Integrations\Support\FrameworkExceptionTable;
use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\NodeFinder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The source-of-truth guard behind {@see FrameworkExceptionTable}'s `HttpException` rows.
 *
 * Those rows are a hand-maintained full set, and the set is not ours: it is whatever the application
 * RESOLVED. So this reads the installed packages rather than a list somebody typed — every class that
 * pins a literal status in its own constructor is enumerated, the number is parsed back out of that
 * constructor, and the table is held to it. A class added upstream, or a row typed with the wrong
 * number, fails here instead of shipping a status the server never sends. Fifteen of Symfony's sixteen
 * were missing when this was written, each publishing a 500.
 *
 * Only DIRECT children of `HttpException` are candidates: a grandchild's `parent::__construct` first
 * argument is its parent's first parameter, not a status (`ThrottleRequestsException` passes a
 * retry-after there), and it inherits the pin anyway — through the runtime constructor chain, and
 * through the table's subtype-aware matching alike.
 *
 * Scope is the FRAMEWORK, which is what this table speaks for: Symfony's HTTP kernel and Laravel
 * itself. A vendor package's own `HttpException` subclasses belong to that package's integration —
 * Spatie Query Builder's `InvalidQuery` is documented by `QueryBuilderParametersExtension` — so they
 * are neither read here nor owed a row.
 */

/** The installed packages this guard reads, each with the PSR-4 root its source tree is published under. */
const PINNING_PACKAGES = [
    ['symfony/http-kernel', '', 'Symfony\\Component\\HttpKernel\\'],
    ['laravel/framework', 'src/Illuminate', 'Illuminate\\'],
];

/**
 * Every installed class that extends `HttpException` DIRECTLY and pins a literal status in its own
 * constructor, mapped to the status it pins — read off the vendor source, never off the table.
 *
 * @return array<string, string>
 */
function frameworkHttpExceptionPins(): array
{
    $pins = [];

    foreach (PINNING_PACKAGES as [$package, $sourceDir, $prefix]) {
        $root = rtrim((string) InstalledVersions::getInstallPath($package), '/');
        $root = $sourceDir === '' ? $root : $root.'/'.$sourceDir;

        /** @var iterable<SplFileInfo> $files */
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $path = $file->getPathname();
            // A cheap prefilter over a package with thousands of files; reflection below is what
            // actually decides, so this only has to be no narrower than the truth.
            if (! str_contains((string) file_get_contents($path), 'HttpException')) {
                continue;
            }

            $relative = substr($path, strlen($root) + 1, -strlen('.php'));
            $fqcn = $prefix.str_replace('/', '\\', $relative);

            if (! class_exists($fqcn)) {
                continue;
            }

            $parent = (new ReflectionClass($fqcn))->getParentClass();
            if ($parent === false || $parent->getName() !== HttpException::class) {
                continue;
            }

            $status = frameworkPinnedStatus($path);
            if ($status !== null) {
                $pins[$fqcn] = $status;
            }
        }
    }

    ksort($pins);

    return $pins;
}

/**
 * The literal status a class's own constructor hands `parent::__construct`, or null where it declares
 * no constructor (nothing is pinned, so there is nothing to hold the table to) or hands something this
 * cannot fold.
 */
function frameworkPinnedStatus(string $file): ?string
{
    $constructor = ParsedClassFile::methods($file)['__construct'] ?? null;
    if ($constructor === null) {
        return null;
    }

    foreach ((new NodeFinder)->findInstanceOf($constructor, StaticCall::class) as $call) {
        if (! $call->class instanceof Name || $call->class->toString() !== 'parent') {
            continue;
        }

        $first = $call->args[0] ?? null;
        if ($first?->value instanceof Int_) {
            return (string) $first->value->value;
        }
    }

    return null;
}

/**
 * The classes the given classifier answers for differently from what they pin — the comparison the
 * guard below makes, extracted so the state it must REFUSE can be run through it too.
 *
 * @param  array<string, string>  $pins
 * @param  callable(string): string  $classifier
 * @return list<string>
 */
function frameworkPinDisagreements(array $pins, callable $classifier): array
{
    $out = [];
    foreach ($pins as $fqcn => $status) {
        if ($classifier($fqcn) !== $status) {
            $out[] = $fqcn;
        }
    }

    return $out;
}

it('classifies every installed HttpException subclass at the status it pins', function (): void {
    $pins = frameworkHttpExceptionPins();

    $symfony = array_filter($pins, static fn (string $f): bool => str_starts_with($f, 'Symfony\\'), ARRAY_FILTER_USE_KEY);
    $laravel = array_filter($pins, static fn (string $f): bool => str_starts_with($f, 'Illuminate\\'), ARRAY_FILTER_USE_KEY);

    // A scan that matched nothing must fail rather than pass forever. Both packages really do ship
    // these — sixteen and three at the versions this was written against — so a walk that stopped
    // seeing them, or a package that moved to constants this cannot fold, is the defect. Stated as a
    // floor rather than an equality so that a class ADDED upstream fails on the line below, which
    // names it, rather than here on a count that does not.
    expect(count($symfony))->toBeGreaterThanOrEqual(16)
        ->and(count($laravel))->toBeGreaterThanOrEqual(3);

    expect(frameworkPinDisagreements($pins, FrameworkExceptionTable::classification(...)))->toBe([]);
});

it('refuses a table that has gone short, or that pins the wrong number', function (): void {
    // The guard above is only worth what it would reject, so the two ways the table rots are run
    // through the same comparison: a class dropped from it (which classifies to the unplaced 500) and
    // a row typed with a number the class does not pin.
    $pins = frameworkHttpExceptionPins();
    $dropped = static fn (string $fqcn): string => FrameworkExceptionTable::UNPLACED_STATUS;
    $mistyped = static fn (string $fqcn): string => '418';

    expect(frameworkPinDisagreements($pins, $dropped))->toBe(array_keys($pins))
        ->and(frameworkPinDisagreements($pins, $mistyped))->toBe(array_keys($pins))
        // …and no pinned status is itself 500 or 418, or the two rows above would agree by accident.
        ->and(array_values($pins))->not->toContain(FrameworkExceptionTable::UNPLACED_STATUS)
        ->and(array_values($pins))->not->toContain('418');
});

it('names every status it pins, so no error goes out described as a bare Error', function (): void {
    // A status with no phrase publishes a generic `Error` description and no component name at all, so
    // a class mapped without its phrase trades one under-description for another.
    foreach (frameworkHttpExceptionPins() as $status) {
        expect(FrameworkExceptionTable::reason($status))->not->toBe('Error')
            ->and(FrameworkExceptionTable::componentName($status))->not->toBeNull();
    }
});
