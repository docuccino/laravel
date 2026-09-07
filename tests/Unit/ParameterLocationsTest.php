<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Document\Parameter;
use Docuccino\Laravel\Support\OverrideHint;
use Docuccino\Laravel\Support\ParameterLocations;

/*
 * The parameter locations: the reader that folds an author's word against them, and the set itself.
 *
 * Two halves. The reader is a mapping table, so it owes a row per entry — every case and padding an
 * author might write — plus the unknown-entry degradation its callers act on.
 *
 * The SET is a fact several things depend on: what a rename may move, the order a document publishes
 * its parameters in, where a named example is looked for, and which attribute owns each location.
 * `Parameter::LOCATIONS` is the one owner, so asking it for its own answer would prove nothing — the
 * set and its ORDER are written out literally below, and confirmed against the order a document really
 * comes out in rather than against the constant that decides it.
 */

it('reads every location it declares, whatever case or padding the author wrote it in', function (string $location): void {
    expect(ParameterLocations::read($location))->toBe($location)
        ->and(ParameterLocations::read(strtoupper($location)))->toBe($location)
        ->and(ParameterLocations::read(ucfirst($location)))->toBe($location)
        ->and(ParameterLocations::read('  '.$location.' '))->toBe($location);
})->with(ParameterLocations::all());

/*
 * The degradation, which is the half a caller acts on: a value naming no location comes back as null so
 * the caller can quote the author's own word back at them rather than guessing at a location and
 * renaming or dropping a parameter nobody named.
 */
it('reads a word that names no location as no location at all', function (string $written): void {
    expect(ParameterLocations::read($written))->toBeNull();
})->with([
    'a location OpenAPI has not got' => ['body'],
    'nothing at all' => [''],
    'whitespace only' => ['   '],
    'a location spelled as a sentence' => ['in the query'],
    'a location with an inner space' => ['qu ery'],
    'plural' => ['queries'],
]);

/*
 * OAS 3.2 declares a FIFTH parameter location, and this is its row rather than its absence.
 *
 * `querystring` describes the WHOLE query string as one value, so it names no parameter: there is no
 * `name` for a rename to move, for an example declaration to look up, or for an attribute to own.
 * Nothing this product mints publishes one either. So every table keyed on a location is keyed on a
 * NAMED location and reads this as no location at all — and one an overlay writes is published after
 * the four that are named, which is a position rather than a crash.
 */
it('reads OAS 3.2\'s querystring as no location, because it names no parameter', function (): void {
    expect(ParameterLocations::read('querystring'))->toBeNull()
        ->and(ParameterLocations::all())->not->toContain('querystring')
        ->and(parameterLocationTable(OverrideHint::class, 'PARAMETER_ATTRIBUTES'))->not->toHaveKey('querystring')
        ->and(parameterPublishedOrder(['querystring', 'cookie', 'query']))->toBe(['query', 'cookie', 'querystring']);
});

it('quotes every location it declares, and nothing that is not one', function (): void {
    $quoted = ParameterLocations::quoted();

    foreach (ParameterLocations::all() as $location) {
        expect($quoted)->toContain('`'.$location.'`');
    }

    expect(substr_count($quoted, '`'))->toBe(2 * count(ParameterLocations::all()));
});

/**
 * A private const of `$class`, by name.
 *
 * @return array<array-key, mixed>
 */
function parameterLocationTable(string $class, string $name): array
{
    $constant = (new ReflectionClass($class))->getReflectionConstant($name);

    /** @var array<array-key, mixed> $value */
    $value = $constant === false ? [] : $constant->getValue();

    return $value;
}

/**
 * The locations of these parameters as a canonical document publishes them — the axis the declared
 * order acts on, rather than the constant that decides it, so a guard here cannot agree with whatever
 * the constant happens to say. All four carry one name, so the location is the only thing ordering them.
 *
 * @param  list<string>  $locations
 * @return list<string>
 */
function parameterPublishedOrder(array $locations): array
{
    $canonical = (new Canonicalizer)->canonicalize([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'Locations', 'version' => '1.0.0'],
        'paths' => ['/things' => ['get' => [
            'parameters' => array_map(
                static fn (string $in): array => ['name' => 'p', 'in' => $in],
                $locations,
            ),
            'responses' => ['200' => ['description' => 'OK']],
        ]]],
    ]);

    /** @var list<array{in: string}> $published */
    $published = $canonical['paths']['/things']['get']['parameters'];

    return array_map(static fn (array $parameter): string => $parameter['in'], $published);
}

/*
 * The set and its order, stated independently of the code that declares them. Membership decides what a
 * rename may move and what an example declaration may find; the order is published in every document
 * the canonicaliser touches. A fifth location arriving in the constant is a decision, and it fails here
 * until somebody makes it.
 */
it('declares the four locations that name a parameter, in the order a document publishes them', function (): void {
    expect(Parameter::LOCATIONS)->toBe(['path', 'query', 'header', 'cookie'])
        ->and(ParameterLocations::all())->toBe(['cookie', 'header', 'path', 'query'])
        // And the order really is the published one, so the list above is a claim about something.
        ->and(parameterPublishedOrder(['cookie', 'header', 'query', 'path']))->toBe(['path', 'query', 'header', 'cookie']);
});

/*
 * The one table that still spells the locations out for itself, because its values are attribute names
 * rather than the set. It has to be TOTAL over the set, or a parameter of some location names no lever
 * and its reader is told nothing where a sibling is told exactly what to write.
 */
it('names the attribute that owns a parameter of every location, and of nothing else', function (): void {
    $owners = array_map('strval', array_keys(parameterLocationTable(OverrideHint::class, 'PARAMETER_ATTRIBUTES')));
    sort($owners, SORT_STRING);

    expect($owners)->toBe(ParameterLocations::all());
});
