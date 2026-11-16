<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AuthorData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AuthorPageData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\PageMetaData;

/*
 * A page that is its own envelope, `{data, meta}`, handed out by a named constructor that takes spatie's
 * root wrap off the instance it builds. Under a global `data.wrap` the server sends the page at the root,
 * so the document must too — both where the action returns it and where `#[Response]` names the class,
 * which has no return to read and so is answered by the class alone. Either way it is the page's own
 * component, with its descriptions, and not a second `data` around it.
 */

it('publishes a page that unwraps itself at the root, returned or declared by class', function (): void {
    bootLaravelData('data');

    $result = selfUnwrappedPageBuild([
        AuthorPageData::class => new ClassMetadata(AuthorPageData::class, [
            new PropertyMetadata('data', new ListT(new ClassT(AuthorData::class)), 'The authors on this page.'),
            new PropertyMetadata('meta', new ClassT(PageMetaData::class), 'Where this page sits in the whole list.'),
        ]),
        PageMetaData::class => new ClassMetadata(PageMetaData::class, [
            new PropertyMetadata('current_page', ScalarT::int()),
            new PropertyMetadata('total', ScalarT::int()),
        ]),
        AuthorData::class => new ClassMetadata(AuthorData::class, [
            new PropertyMetadata('name', ScalarT::string()),
            new PropertyMetadata('email', ScalarT::string()),
        ]),
    ], [new ReturnSite(new ClassT(AuthorPageData::class), new SourceLocation(''))]);

    expectSelfUnwrappedPage($result);
});

it('publishes the fixture app\'s page as the real engine recovers it', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
    bootLaravelData('data');

    // What the real engine hands the operation for `return AuthorPageData::page(…)`: the named
    // constructor declares `self`, so the operation's body is the page class itself.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeMany(
        'app/Http/Controllers/AuthorPageController.php',
        'App\\Http\\Controllers\\AuthorPageController',
        ['index'],
    )['index']);

    expect($analysis->returns)->toHaveCount(1)
        ->and($analysis->returns[0]->type)->toEqual(new ClassT('App\\Data\\AuthorPageData'));

    // The real metadata and return, re-keyed onto the loadable twins whose sources match the app's.
    $twins = [
        'App\\Data\\AuthorPageData' => AuthorPageData::class,
        'App\\Data\\PageMetaData' => PageMetaData::class,
        'App\\Data\\AuthorData' => AuthorData::class,
    ];
    $escaped = static fn (string $fqcn): string => substr(json_encode($fqcn, JSON_THROW_ON_ERROR), 1, -1);
    $rekey = static fn (array $data): array => json_decode(
        strtr(json_encode($data, JSON_THROW_ON_ERROR), array_combine(array_map($escaped, array_keys($twins)), array_map($escaped, $twins))),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $classes = [];
    foreach ($twins as $real => $twin) {
        $classes[$twin] = ClassMetadata::fromArray($rekey(FixtureRunner::classMetadata($real)));
    }

    expectSelfUnwrappedPage(selfUnwrappedPageBuild($classes, [ReturnSite::fromArray($rekey($analysis->returns[0]->toArray()))]));
})->group('fixture');
