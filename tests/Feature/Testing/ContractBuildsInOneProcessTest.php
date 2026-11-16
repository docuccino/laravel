<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/*
 * A test suite holds each of its documents to the committed artifact in one PHP process, so every document
 * after the first is built by an engine booted in a process that has booted one already. What a build
 * produces is a function of the code alone, so the second document must be exactly what it is when built on
 * its own. Real engine, real fixture app, the adapter's own `ApiContract::build()`: the runner's
 * `contract-builds` mode builds the named documents in turn.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('builds the second document of a process exactly as it builds it alone', function (bool $perTest): void {
    $alone = FixtureRunner::contractBuilds(['second'])['second'];
    $together = FixtureRunner::contractBuilds(['first', 'second'], $perTest)['second'];

    // Each action answers with a status its own code chooses, which only the engine reads: built with no
    // engine, the document says its operations answer nothing at all.
    expect($alone['statuses'])->toBe([
        '/api/second/constant' => ['422'],
        '/api/second/constructed' => ['409'],
        '/api/second/helperStatus' => ['401'],
        '/api/second/json' => ['401'],
    ])
        ->and($together['statuses'])->toBe($alone['statuses'])
        ->and($together['paths'])->toBe($alone['paths'])
        ->and($together['components'])->toBe($alone['components']);
})->with([
    'in one test' => [false],
    'in a test of its own' => [true],
])->group('fixture');
