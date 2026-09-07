<?php

declare(strict_types=1);

use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Laravel\Versioning\ParameterRenameEdit;
use Docuccino\Laravel\Versioning\VerbOutcome;

/*
 * The two nodes a real versioned build never hands `#[RenamedParameter]`, asked of the verb directly.
 *
 * Every operation of a versioned document is given the version header, so `parameters` is always there
 * and every parameter the recovery wrote carries an identity — which means the degradations below are
 * reachable only from a node something else wrote: a webhook, which the header pass leaves alone
 * because a webhook is a request the SERVER makes, or an overlay-written parameter. They are behaviour
 * either way, so they are pinned here rather than left to whichever build meets one first.
 */

/** The one verb every row below is asked of: `search` in the code today, `q` in the versions before. */
function parameterRename(): ParameterRenameEdit
{
    return new ParameterRenameEdit('query', 'q', 'search');
}

it('leaves an operation that declares no parameters alone, and reports nothing found', function (): void {
    $operation = ['x-docuccino' => ['id' => 'op:v1:one'], 'responses' => ['200' => ['description' => 'OK']]];
    $outcome = VerbOutcome::Absent;

    expect(parameterRename()->apply($operation, 'op:v1:one', new IdentityGenerator, $outcome))->toBe($operation)
        ->and($outcome)->toBe(VerbOutcome::Absent);
});

it('renames a parameter that carries no identity without inventing one', function (): void {
    // Nothing to re-mint from and nothing to correct: an id is a fact about where a node came from, and
    // minting one here would claim this parameter was recovered when it was written by hand.
    $operation = ['parameters' => [['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string']]]];
    $outcome = VerbOutcome::Absent;

    $edited = parameterRename()->apply($operation, 'op:v1:one', new IdentityGenerator, $outcome);

    expect($outcome)->toBe(VerbOutcome::Applied)
        ->and($edited['parameters'][0])->toBe(['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']])
        ->and($edited['parameters'][0])->not->toHaveKey('x-docuccino');
});

it('skips a parameter written as a $ref, which is shared with every site that references it', function (): void {
    // It states no name here, so there is nothing to rename — and renaming the component it points at
    // would rename it for every other operation too, including ones a scope was written to exclude.
    $operation = ['parameters' => [['$ref' => '#/components/parameters/Search']]];
    $outcome = VerbOutcome::Absent;

    expect(parameterRename()->apply($operation, 'op:v1:one', new IdentityGenerator, $outcome))->toBe($operation)
        ->and($outcome)->toBe(VerbOutcome::Absent);
});

it('re-mints the identity from where the operation stands when the operation carries none', function (): void {
    // The same fallback a forked schema's ids are re-minted against: the id has to stay a function of
    // the thing, and an operation with no identity of its own still has a position.
    $operation = ['parameters' => [[
        'x-docuccino' => ['id' => 'par:v1:whatever'],
        'name' => 'search',
        'in' => 'query',
    ]]];
    $outcome = VerbOutcome::Absent;
    $identity = new IdentityGenerator;

    $edited = parameterRename()->apply($operation, 'paths//api/things/get', $identity, $outcome);

    expect($edited['parameters'][0]['x-docuccino']['id'])
        ->toBe($identity->parameterId('paths//api/things/get', 'query', 'q'));
});

/*
 * The re-mint reads the DOCUMENT's `in`, never the author's word for it. The match folds case — `in:
 * 'Query'` says what `in: 'query'` says — so a parameter published as `Query` is matched and renamed
 * here, and minting from the author's lowercased word would leave it carrying an id that neither its
 * old mint nor a fresh mint of its new name produces: a node the differ pairs with nothing. Only a
 * parameter something other than this product's own recovery wrote is spelled that way, which is
 * exactly why it is pinned rather than reasoned about.
 */
it('re-mints from the location the document spells, not the one the declaration spells', function (): void {
    $identity = new IdentityGenerator;
    $operation = ['parameters' => [[
        'x-docuccino' => ['id' => $identity->parameterId('op:v1:one', 'Query', 'search')],
        'name' => 'search',
        'in' => 'Query',
    ]]];
    $outcome = VerbOutcome::Absent;

    $edited = parameterRename()->apply($operation, 'op:v1:one', $identity, $outcome);

    expect($outcome)->toBe(VerbOutcome::Applied)
        ->and($edited['parameters'][0]['x-docuccino']['id'])
        ->toBe($identity->parameterId('op:v1:one', 'Query', 'q'))
        // And the two spellings really do mint different ids, so the line above says which one was read
        // rather than merely naming a hash.
        ->and($identity->parameterId('op:v1:one', 'Query', 'q'))
        ->not->toBe($identity->parameterId('op:v1:one', 'query', 'q'));
});

it('raises the strongest outcome it saw rather than overwriting an earlier one', function (): void {
    // One verb is walked across every operation in scope, so an operation that had nothing to rename
    // must not undo the edit another one took.
    $outcome = VerbOutcome::Applied;
    $operation = ['responses' => ['200' => ['description' => 'OK']]];

    parameterRename()->apply($operation, 'op:v1:one', new IdentityGenerator, $outcome);

    expect($outcome)->toBe(VerbOutcome::Applied);
});
