<?php

declare(strict_types=1);

use Docuccino\Laravel\Tests\Fixtures\Webhooks\Scan\Nested\Deep;
use Docuccino\Laravel\Tests\Fixtures\Webhooks\Scan\Payload;
use Docuccino\Laravel\Webhooks\WebhookClasses;

/**
 * The source scan behind webhook discovery. It answers a directory with the classes it declares, and
 * the answer must be a function of what is written there — never of the order the filesystem hands
 * the files over.
 */
beforeEach(function (): void {
    $this->scanned = WebhookClasses::in(dirname(__DIR__).'/Fixtures/Webhooks/Scan');
});

it('answers the classes a directory tree declares, sorted and namespace-qualified', function (): void {
    // Deep sorts before Payload by FQCN and after it by path, so an answer sorted by NAME is what this
    // pins — a scan that inherited readdir order would be neither reliably.
    expect($this->scanned)->toBe([Deep::class, Payload::class]);
});

it('reads `class` as a declaration only where one is written', function (string $absent): void {
    // `Foo::class`, `new class {}` and the word inside a docblock all tokenise as T_CLASS, and an
    // interface, a trait and an enum are not classes a `#[Webhook]` can be discovered on.
    expect($this->scanned)->not->toContain($absent);
})->with([
    'an interface' => ['Docuccino\Laravel\Tests\Fixtures\Webhooks\Scan\Contract'],
    'a trait' => ['Docuccino\Laravel\Tests\Fixtures\Webhooks\Scan\Marker'],
    'an enum' => ['Docuccino\Laravel\Tests\Fixtures\Webhooks\Scan\Colour'],
    // Declared, but under a name its file cannot autoload — so nothing could reflect it.
    'a class no autoloader answers for' => ['Docuccino\Laravel\Tests\Fixtures\Webhooks\Scan\NotWhatTheFileIsCalled'],
]);

it('reads only PHP files, wherever in the tree they sit', function (): void {
    // The `.md` beside the sources is never opened, and the nested directory is descended into.
    expect($this->scanned)->toContain(Deep::class)
        ->and(count($this->scanned))->toBe(2);
});
