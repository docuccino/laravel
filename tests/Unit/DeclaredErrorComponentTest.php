<?php

declare(strict_types=1);

use Docuccino\Laravel\Exceptions\DeclaredErrorComponent;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\ApiException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\InheritedApiException;
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

it('replaces the status default and nothing a producer named itself', function (?string $claim, string $status, bool $expected): void {
    expect(DeclaredErrorComponent::mayReplace($claim, $status))->toBe($expected);
})->with([
    'nothing claimed yet' => [null, '409', true],
    'the status default' => ['Conflict', '409', true],
    'a name a producer chose' => ['InvoiceMissing', '409', false],
    'a status with no default, claimed anyway' => ['Whatever', '410', false],
    'a status with no default, unclaimed' => [null, '410', true],
]);
