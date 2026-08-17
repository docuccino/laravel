<?php

declare(strict_types=1);

use Docuccino\Laravel\Exceptions\DeclaredErrorComponent;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\ApiException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\InheritedApiException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\MistypedNameException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\OverridingApiException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\ThingMissingException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\UndeclaredException;

it('reads the name an exception declares on itself', function (): void {
    $declaration = DeclaredErrorComponent::on(ThingMissingException::class);

    expect($declaration?->name)->toBe('ResourceMissing')
        ->and($declaration?->declaredBy)->toBe(ThingMissingException::class)
        ->and($declaration?->file)->toEndWith('ThingMissingException.php')
        ->and($declaration?->line)->toBeGreaterThan(0);
});

it('inherits a declaration from the base that carries it', function (): void {
    $declaration = DeclaredErrorComponent::on(InheritedApiException::class);

    // The FILE is the base's, not the subclass's — which is what the fragment key has to record.
    expect($declaration?->name)->toBe('ApiFailure')
        ->and($declaration?->declaredBy)->toBe(ApiException::class)
        ->and($declaration?->file)->toEndWith('ApiException.php');
});

it('lets the nearest declaring class win', function (): void {
    $declaration = DeclaredErrorComponent::on(OverridingApiException::class);

    expect($declaration?->name)->toBe('PolicyRefused')
        ->and($declaration?->declaredBy)->toBe(OverridingApiException::class);
});

it('answers nothing for an exception whose hierarchy declares nothing', function (): void {
    expect(DeclaredErrorComponent::on(UndeclaredException::class))->toBeNull();
});

it('answers nothing for a class that cannot be loaded', function (): void {
    // The engine reports FQCNs from analysis, which can name a class this process never autoloads.
    expect(DeclaredErrorComponent::on('Acme\\Nope\\NeverLoaded'))->toBeNull();
});

it('answers nothing for a class whose attribute cannot be constructed', function (): void {
    // `#[ErrorComponent(5)]` is a typo in application source, and instantiating it throws a `TypeError`
    // naming the file it was written in — a message the build would print into the emitted document.
    expect(DeclaredErrorComponent::on(MistypedNameException::class))->toBeNull();
});

it('replaces the status default and nothing a producer named itself', function (?string $claim, bool $isStatusDefault, bool $expected): void {
    // The fact comes from the WRITE, not from the value: a producer that named a body "NotFound" for a 404
    // named it, and re-deriving the question from the string would read that as nobody having named it.
    expect(DeclaredErrorComponent::mayReplace($claim, $isStatusDefault))->toBe($expected);
})->with([
    'nothing claimed yet' => [null, false, true],
    'the status default' => ['Conflict', true, true],
    'a name a producer chose' => ['InvoiceMissing', false, false],
    'a chosen name that spells the status default' => ['NotFound', false, false],
]);
