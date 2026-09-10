<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\SpatieData\WrapReason;
use Docuccino\Laravel\Integrations\SpatieData\WrapResolver;
use Docuccino\Laravel\Integrations\SpatieData\WrapSightings;
use Docuccino\Laravel\Integrations\SpatieData\WrapUncertainty;
use Docuccino\Laravel\Integrations\Support\ParsedClassFile;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AuthorData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ComputedWrapData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\DeferredContextProblemData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\HelperContextProblemData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedTransformDisabledData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedUnwrappedData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedWrapDisabledData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\OwnResponseProblemData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ProblemDocumentData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\SiblingWrapData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\WrappedData;

/*
 * Spatie spells unwrapping the same way on a data object, on a collection it holds and on a
 * transformation context, so a sighting of the vocabulary is not a fact about the response root. The
 * ladder makes each reading say WHICH switch it saw and how far that switch reaches; these guard the
 * two halves separately and assert their union, because either alone is silent about the other:
 *
 *   - the READING half — which reasons a real class's source raises — is proven fixture by fixture,
 *     one per reason plus the shapes that raise none;
 *   - the COMPOSITION half — what the resolver's three answers do with a set of reasons — is driven
 *     over all 8 subsets, so no combination is left to the fixtures that happen to exist.
 *
 * The composition rules are restated here in the test's own words and never read off the class: a
 * guard that asks the code for its own rule agrees with whatever the code does.
 */

it('states which switch every reason read, and how far it goes', function (): void {
    // The table, restated: does the reading take the ROOT's envelope off, does the switch it read
    // reach values nested under the root, and did it settle anything at all.
    $rows = [
        'SelfWithoutWrapping' => [true, false, true],
        'SelfTransformDisabled' => [true, true, true],
        'UnattributedDisabling' => [false, false, false],
    ];

    expect(array_map(static fn (WrapReason $reason): string => $reason->name, WrapReason::cases()))
        ->toEqualCanonicalizing(array_keys($rows));

    foreach (WrapReason::cases() as $reason) {
        [$root, $propagates, $conclusive] = $rows[$reason->name];

        expect($reason->unwrapsRoot())->toBe($root)
            ->and($reason->propagates())->toBe($propagates)
            ->and($reason->isConclusive())->toBe($conclusive);
    }

    // Anti-vacuity: a column where every row agreed would pass whatever the rule was, so all three
    // have to discriminate. `unwrapsRoot` and `isConclusive` agree over these rows and are still two
    // questions — the reading that names no receiver is simply the only one answering no to either,
    // which is a fact about which rows exist rather than a rule the code may lean on.
    $columns = [
        'unwrapsRoot' => static fn (WrapReason $r): bool => $r->unwrapsRoot(),
        'propagates' => static fn (WrapReason $r): bool => $r->propagates(),
        'isConclusive' => static fn (WrapReason $r): bool => $r->isConclusive(),
    ];

    foreach ($columns as $column) {
        expect(array_unique(array_map($column, WrapReason::cases())))->toHaveCount(2);
    }
});

it('composes the three answers exactly as the contract states them', function (): void {
    $drops = new ReflectionMethod(WrapResolver::class, 'dropsEnvelope');
    $doubt = new ReflectionMethod(WrapResolver::class, 'leavesItInDoubt');
    $nested = new ReflectionMethod(WrapResolver::class, 'nestedStaysWrapped');

    // The rules, written from the contract rather than read off the class. The envelope comes off
    // where a reading speaks for the root; what is nested under it stays wrapped unless a reading
    // threw the switch that propagates, or a reading settled nothing and so could have been that
    // switch; and an envelope nothing settled is the one in doubt.
    $rootUnwrapped = static fn (array $names): bool => in_array('SelfWithoutWrapping', $names, true)
        || in_array('SelfTransformDisabled', $names, true);
    $inDoubt = static fn (array $names): bool => ! $rootUnwrapped($names)
        && in_array('UnattributedDisabling', $names, true);
    $nestedWrapped = static fn (array $names): bool => ! in_array('SelfTransformDisabled', $names, true)
        && ! in_array('UnattributedDisabling', $names, true);

    $cases = WrapReason::cases();
    $answers = $predicted = $subsets = [];

    for ($mask = 0; $mask < 2 ** count($cases); $mask++) {
        $standing = [];
        foreach ($cases as $bit => $reason) {
            if (($mask & (1 << $bit)) !== 0) {
                $standing[$reason->name] = $reason;
            }
        }

        $names = array_keys($standing);
        $label = $names === [] ? 'nothing stands' : implode('+', $names);

        $subsets[$label] = $names;
        $answers[$label] = [
            'drops' => $drops->invoke(null, $standing),
            'doubt' => $doubt->invoke(null, $standing),
            'nested wrapped' => $nested->invoke(null, $standing),
        ];
        $predicted[$label] = [
            'drops' => $rootUnwrapped($names),
            'doubt' => $inDoubt($names),
            'nested wrapped' => $nestedWrapped($names),
        ];
    }

    expect($subsets)->toHaveCount(8)
        ->and($answers)->toBe($predicted);

    // …and the public answer is the composed one, for every subset. `wrapsNested()` is the consumer
    // that used to read a case name out of the standing set and a field off the uncertainty instead
    // of going through the table, so the memos are seeded with the subset alone and nothing else:
    // an answer that leans on anything but the reasons standing disagrees here.
    $asked = $expected = [];

    foreach ($subsets as $label => $names) {
        $resolver = new WrapResolver('data');
        $seed = static function (string $property, mixed $value) use ($resolver): void {
            $slot = new ReflectionProperty(WrapResolver::class, $property);
            $slot->setValue($resolver, [...$slot->getValue($resolver), 'Seeded' => $value]);
        };

        $seed('keys', 'data');
        $seed('unsettled', null);
        $seed('standing', array_combine($names, array_map(
            static fn (string $name): WrapReason => constant(WrapReason::class.'::'.$name),
            $names,
        )));

        $asked[$label] = $resolver->wrapsNested('Seeded');
        $expected[$label] = $nestedWrapped($names);
    }

    expect($asked)->toBe($expected);

    // The two root answers must also be exclusive: an envelope that came off cannot be in doubt.
    foreach ($subsets as $label => $names) {
        expect($answers[$label]['drops'] && $answers[$label]['doubt'])->toBeFalse();
    }

    // Discrimination. Each wrong rule is one this codebase has shipped or nearly shipped — a sighting
    // trusted whatever its receiver, only one spelling allowed to speak for the root, the root's own
    // envelope taken to reach what is nested, an unattributable disabling treated as the root's
    // problem alone — and each must disagree with the real class somewhere.
    $wrong = [
        'drops' => [
            'any sighting at all takes the envelope off' => static fn (array $n): bool => $n !== [],
            'only the transformation switch speaks for the root' => static fn (array $n): bool => in_array('SelfTransformDisabled', $n, true),
        ],
        'nested wrapped' => [
            'the root going bare takes what is nested with it' => static fn (array $n): bool => ! $rootUnwrapped($n),
            'an unattributable disabling is the root problem alone' => static fn (array $n): bool => ! in_array('SelfTransformDisabled', $n, true),
        ],
    ];

    foreach ($wrong as $answer => $rules) {
        foreach ($rules as $rule) {
            $guesses = $real = [];
            foreach ($subsets as $label => $names) {
                $guesses[$label] = $rule($names);
                $real[$label] = $answers[$label][$answer];
            }

            expect($guesses)->not->toBe($real);
        }
    }
});

it('raises each reason off the shape that spells it, and none off the shapes that do not', function (): void {
    $sibling = dirname(__DIR__, 3).'/Fixtures/SpatieData/SiblingWrapData.php';

    $rows = [
        // `$this->withoutWrapping()` — a chain of method hops off the object itself.
        ProblemDocumentData::class => ['SelfWithoutWrapping'],
        // A disabled transformation handed straight to `$this->transform(…)`.
        OwnResponseProblemData::class => ['SelfTransformDisabled'],
        NestedWrapDisabledData::class => ['SelfTransformDisabled'],
        // …and the same, built into a local first, which is how it reads with a second option set.
        DeferredContextProblemData::class => ['SelfTransformDisabled'],
        // A hop through a helper: the vocabulary is here and no receiver can be named for it.
        HelperContextProblemData::class => ['UnattributedDisabling'],
        // The vocabulary aimed at a value the object HOLDS, in both of spatie's spellings. Neither
        // speaks for the root and neither reaches the ordinary serialisation of anything, so both
        // raise nothing — a different silence from the one below, and the resolver rows are where
        // the two are told apart.
        NestedUnwrappedData::class => [],
        NestedTransformDisabledData::class => [],
        // Classes that say nothing about wrapping at all.
        AuthorData::class => [],
        WrappedData::class => [],
        ComputedWrapData::class => [],
        // A class whose FILE holds a neighbour that unwraps itself. The neighbour's `$this` is not
        // this class's, so it raises nothing here…
        SiblingWrapData::class => [],
    ];

    $raised = $expected = [];

    foreach ($rows as $fqcn => $names) {
        $file = (new ReflectionClass($fqcn))->getFileName();
        expect($file)->toBeString();

        $standing = WrapSightings::standing(ParsedClassFile::methodsOf((string) $file, $fqcn));

        $raised[$fqcn] = array_keys($standing);
        sort($raised[$fqcn]);
        $expected[$fqcn] = $names;
        sort($expected[$fqcn]);
    }

    // …and the positive control the scoping needs: read the SAME file for the neighbour and the
    // reason is there. Without this the row above would pass just as well on a scan that found
    // nothing anywhere.
    $neighbour = 'Docuccino\Laravel\Tests\Fixtures\SpatieData\SiblingWrapProblemData';
    $raised[$neighbour] = array_keys(WrapSightings::standing(ParsedClassFile::methodsOf($sibling, $neighbour)));
    $expected[$neighbour] = ['SelfWithoutWrapping'];

    expect($raised)->toBe($expected);

    // The denominator the two held rows need: their sources really do spell the vocabulary, so the
    // empty answer is a receiver the read attributed and declined to act on rather than a read that
    // saw nothing. Without this they would pass on a scanner that had stopped matching anything.
    $held = [
        NestedUnwrappedData::class => 'withoutWrapping',
        NestedTransformDisabledData::class => 'WrapExecutionType::Disabled',
    ];

    foreach ($held as $fqcn => $token) {
        expect(file_get_contents((string) (new ReflectionClass($fqcn))->getFileName()))->toContain($token);
    }

    // Every reason has to be raised by something here, or a row's expectation is only pinning silence.
    $all = array_merge(...array_values($raised));
    expect(array_unique($all))->toHaveCount(count(WrapReason::cases()));
});

it('publishes the envelope a class really sends, and says which ones it could not settle', function (): void {
    // The published answer, with `data.wrap` configured. `key` is the envelope the document puts on the
    // response root; `code` is the diagnostic the author gets.
    $rows = [
        // Unwrapped: the class takes its own envelope off, in each spelling spatie offers.
        'withoutWrapping() on itself' => [ProblemDocumentData::class, null, null],
        'a disabled transformation of itself' => [OwnResponseProblemData::class, null, null],
        'the same, built into a local first' => [DeferredContextProblemData::class, null, null],
        // Wrapped: what the class disables belongs to a value it HOLDS. Spatie resolves the root's
        // envelope from the root's own wrap, and a `WrapExecutionType` travels only with the
        // transformation it is handed — so neither of these says anything about the root, and the
        // server sends `{"data": …}` for both. Quietly, too: a receiver that was named and is not
        // the root settles the question, so there is nothing to report.
        'withoutWrapping() on a collection it holds' => [NestedUnwrappedData::class, 'data', null],
        'a disabled transformation of a value it holds' => [NestedTransformDisabledData::class, 'data', null],
        // Wrapped: the unwrapping belongs to a neighbour sharing the file.
        'a neighbour in the same file unwraps itself' => [SiblingWrapData::class, 'data', null],
        // Wrapped, and said out loud: the vocabulary is here and its receiver could not be named.
        'a disabling no receiver could be named for' => [HelperContextProblemData::class, 'data', 'spatie-data.root-wrap-unsettled'],
        // Wrapped, and said out loud: the key itself could not be read off the override.
        'a defaultWrap() that returns no literal' => [ComputedWrapData::class, 'data', 'spatie-data.root-wrap-unsettled'],
        // Wrapped, quietly: the ordinary cases.
        'a defaultWrap() returning a literal' => [WrappedData::class, 'record', null],
        'a class that says nothing about wrapping' => [AuthorData::class, 'data', null],
    ];

    $published = $expected = [];

    foreach ($rows as $label => [$fqcn, $key, $code]) {
        $resolver = new WrapResolver('data');

        $published[$label] = [$resolver->key($fqcn), $resolver->diagnose($fqcn)?->code];
        $expected[$label] = [$key, $code];
    }

    expect($published)->toBe($expected);

    // With no wrap configured there is no envelope to be in doubt about, so the diagnostic must go
    // quiet — a report a reader can do nothing with is worse than none.
    $unconfigured = new WrapResolver;
    expect($unconfigured->key(HelperContextProblemData::class))->toBeNull()
        ->and($unconfigured->diagnose(HelperContextProblemData::class))->toBeNull();
});

it('says why every envelope it could not settle is in doubt', function (): void {
    $rows = [
        'DisablingNotAttributed' => ['could not attribute', 'receiver'],
        'DefaultWrapNotLiteral' => ['defaultWrap()', 'literal'],
    ];

    expect(array_map(static fn (WrapUncertainty $u): string => $u->name, WrapUncertainty::cases()))
        ->toEqualCanonicalizing(array_keys($rows));

    $because = [];

    foreach (WrapUncertainty::cases() as $case) {
        [$inBecause, $inHelp] = $rows[$case->name];

        expect($case->because())->toContain($inBecause)
            ->and($case->help())->toContain($inHelp)
            // The author is the audience, so the help has to name the thing they can do.
            ->and($case->help())->toContain('overlay');

        $because[] = $case->because();
    }

    // Two clauses that read alike would make the diagnostic useless whichever fired.
    expect(array_unique($because))->toHaveCount(count(WrapUncertainty::cases()));
});
