<?php

declare(strict_types=1);

use Docuccino\Laravel\Routing\RouteCoverage;
use Docuccino\Laravel\Routing\RouteTemplate;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Whether one segment requirement accepts everything another does. Every row states its answer, and the
 * router is asked too: a covering requirement has to accept each sample the covered one does.
 */
it('says a requirement covers another only where it accepts all of it', function (string $covering, string $covered, bool $covers, array $samples): void {
    expect(RouteCoverage::coversRequirement($covering, $covered))->toBe($covers);

    $route = static fn (string $requirement): Route => (new Route(['GET'], 'x/{v}', static fn (): null => null))->where('v', $requirement);
    foreach ($samples as $sample) {
        $request = Request::create('/x/'.rawurlencode($sample));

        expect($route($covered)->matches($request))->toBeTrue();
        if ($covers) {
            expect($route($covering)->matches($request))->toBeTrue();
        }
    }
    // A "no" is only honest when it is undecided or false, never when a counter-sample is missing: each
    // "no" row with samples names one the covering requirement refuses.
    if (! $covers && $samples !== []) {
        expect($route($covering)->matches(Request::create('/x/'.rawurlencode($samples[0]))))->toBeFalse();
    }
})->with([
    'the same requirement' => ['[0-9]+', '[0-9]+', true, ['7']],
    'the default over digits' => ['[^/]++', '[0-9]+', true, ['2024']],
    'the default over literals' => ['[^/]++', 'summary|latest', true, ['latest']],
    'the default over a UUID' => ['[^/]++', '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}', true, ['1f0e3dad-9ab8-4c3a-8b1d-2f3e4a5b6c7d']],
    'the default over a narrower default' => ['[^/]++', '[^/\.]++', true, ['a']],
    'a catch-all over the default' => ['.*', '[^/]++', true, ['a']],
    'a class over its literals' => ['[a-z]+', 'ada|bob', true, ['bob']],
    'digits over literals outside them' => ['[0-9]+', 'summary|latest', false, ['latest']],
    'digits over the default' => ['[0-9]+', '[^/]++', false, ['ada']],
    'a narrower default over the default' => ['[^/\.]++', '[^/]++', false, ['a.b']],
    'literals over digits' => ['1|2', '[0-9]+', false, ['3']],
    'a class over wide digits' => ['[0-9]+', '\d+', false, ['٣']],
    // Under the router's `u`, `\w` is every script's letters and numbers, and `\d` every script's digits.
    'word characters over bare digits' => ['\w+', '\d+', true, ['٣', '7']],
    'a class of digits over bare digits' => ['[\d]+', '\d+', true, ['٣']],
    'bare digits over a class of them' => ['\d+', '[0-9]+', true, ['7']],
    'bare word characters over wide ones in a class' => ['\w++', '[\w.]+', false, ['a.b']],
    'all but wide digits over all but ASCII ones' => ['[^\d]+', '[^0-9]+', false, ['٣']],
    'all but ASCII digits over all but wide ones' => ['[^0-9]+', '[^\d]+', true, ['a']],
    'all but word characters over all but digits' => ['[^\d]+', '[^\w]+', true, ['!']],
    // A range may start or end with an escape, which this does not read, rather than misread as literals.
    'the default over a range from an escape' => ['[^/]++', '[\.-z]+', false, ['a/b']],
    'literals over a range to an escape' => ['[!.\-]+', '[!-\.]+', false, ['#']],
    'a range from a class escape' => ['[^/]++', '[\d-z]+', false, []],
    'an empty-accepting requirement under a non-empty one' => ['[^/]++', '[a-z]*', false, []],
    'an expression this does not read' => ['(?i)[a-z]+', '[a-z]+', false, []],
]);

it('never says a requirement covers another the router finds a value only the other accepts', function (): void {
    // Requirements spelled from the pieces a class holds — characters, escapes, ranges with an escape at
    // either end, negation, each quantifier — beside the shapes met outside a class. Seeded, so a row
    // that fails fails again.
    mt_srand(1109);
    $atoms = ['a', 'z', 'A', 'Z', '0', '9', '.', '-', '_', '!', '~', '/', '%', '\d', '\w', '\.', '\-', '\/', '\_', '\!'];
    $plain = ['a', 'z', 'A', '0', '9', '.', '!', '/', '_', '~', '\.', '\/', '\!', '\-'];
    $pick = static fn (array $of): string => $of[mt_rand(0, count($of) - 1)];
    $specs = ['.+', '.*', '.++', '\d+', '\w+', '\d++', '\w*', '[^/]++', '[^/\.]++', 'ada|bob', '[0-9]{2}', '(?i)[a-z]+'];
    for ($n = 0; $n < 240; $n++) {
        $body = '';
        for ($piece = mt_rand(1, 3); $piece > 0; $piece--) {
            $body .= mt_rand(0, 2) === 0 ? $pick($plain).'-'.$pick($plain) : $pick($atoms);
        }
        $specs[] = '['.(mt_rand(0, 3) === 0 ? '^' : '').$body.']'.$pick(['+', '*', '++', '*+']);
    }

    // The router's own test: the regex it compiles, against the decoded path less a trailing slash.
    $samples = [...array_map('chr', range(0x20, 0x7E)), 'ab', 'a.b', 'a-b', 'A_9', '7x', '..', '--', 'a/b', '٣', '٣٣', 'é', 'ǅ', 'Ⅻ', '_٣'];
    $accepts = [];
    foreach (array_unique($specs) as $spec) {
        try {
            $regex = (new Route(['GET'], 'x/{v}', static fn (): null => null))->where('v', $spec)->toSymfonyRoute()->compile()->getRegex();
        } catch (Throwable) {
            continue;
        }
        // A requirement PCRE refuses serves no request, and says nothing of coverage.
        if (@preg_match($regex, '') === false) {
            continue;
        }
        $accepts[$spec] = array_values(array_filter($samples, static fn (string $value): bool => preg_match($regex, rtrim('/x/'.$value, '/') ?: '/') === 1));
    }

    $unsound = [];
    $decided = 0;
    foreach ($accepts as $covering => $outer) {
        foreach ($accepts as $covered => $inner) {
            if ($covering === $covered || ! RouteCoverage::coversRequirement((string) $covering, (string) $covered)) {
                continue;
            }
            $decided++;
            $missed = array_diff($inner, $outer);
            if ($missed !== []) {
                $unsound[] = $covering.' over '.$covered.': '.implode(' ', $missed);
            }
        }
    }

    // A reader that decided nothing would be sound over every pair; this one reads most classes.
    expect($unsound)->toBe([])
        ->and($decided)->toBeGreaterThan(2000)
        ->and(count($accepts))->toBeGreaterThan(150);
});

it('never says a possessive segment covers where it takes the character the next token starts with', function (string $covering, string $covered, bool $covers, string $sample): void {
    $route = static fn (string $requirement): Route => (new Route(['GET'], 'x/{a}.{b}', static fn (): null => null))->where('a', $requirement);
    $tokens = static fn (Route $route): array => RouteTemplate::tokens($route) ?? [];
    $outer = $route($covering);
    $inner = $route($covered);
    $request = Request::create('/x/'.$sample);

    expect(RouteCoverage::covers($outer, $tokens($outer), $inner, $tokens($inner)))->toBe($covers)
        ->and($inner->matches($request))->toBeTrue()
        ->and($outer->matches($request))->toBe($covers);
})->with([
    // `[a-z.]++` takes the `.` too and gives nothing back, so `x.y` never reaches the `.` after it.
    'a possessive class holding the separator' => ['[a-z.]++', '[a-z]+', false, 'x.y'],
    'a possessive class stopping at the separator' => ['[a-z]++', '[a-c]+', true, 'ab.y'],
    'a greedy class holding the separator' => ['[a-z.]+', '[a-z]+', true, 'x.y'],
]);
