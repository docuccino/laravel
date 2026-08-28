<?php

declare(strict_types=1);

use Docuccino\Attributes\CookieParameter;
use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\PathParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Patch\Contribution;

/*
 * The `required` argument the query, header and cookie attributes carry is three-valued: the absent
 * argument says nothing, so a declaration written to document a type or a description leaves whatever an
 * integration proved standing, while a written `true` or `false` is the author's own statement at a layer
 * that outranks the recovery.
 *
 * A `#[PathParameter]` has no such argument — OAS requires every `in: path` parameter to be required, so
 * there is nothing for an author to say — and it is here to hold that difference in place.
 */

dataset('parameter attributes with a required argument', [
    'query' => [
        static fn (?bool $required): object => $required === null
            ? new QueryParameter(name: 'search', description: 'Free text.')
            : new QueryParameter(name: 'search', description: 'Free text.', required: $required),
        'query',
        'search',
    ],
    'header' => [
        static fn (?bool $required): object => $required === null
            ? new HeaderParameter(name: 'X-Tenant', description: 'The tenant.')
            : new HeaderParameter(name: 'X-Tenant', description: 'The tenant.', required: $required),
        'header',
        'X-Tenant',
    ],
    'cookie' => [
        static fn (?bool $required): object => $required === null
            ? new CookieParameter(name: 'session', description: 'The session.')
            : new CookieParameter(name: 'session', description: 'The session.', required: $required),
        'cookie',
        'session',
    ],
]);

it('leaves a proven requirement standing when a declaration says nothing about it', function (callable $make, string $in, string $name): void {
    $params = attributeParameters([$make(null)], static function (OperationDraft $operation) use ($in, $name): void {
        $operation->parameter($in, $name)->setRequired(true, Contribution::integration('probe'));
    });

    expect($params[$name]['required'])->toBeTrue()
        ->and($params[$name]['description'])->not->toBeNull();
})->with('parameter attributes with a required argument');

it('takes a proven requirement off when a declaration writes required: false', function (callable $make, string $in, string $name): void {
    $params = attributeParameters([$make(false)], static function (OperationDraft $operation) use ($in, $name): void {
        $operation->parameter($in, $name)->setRequired(true, Contribution::integration('probe'));
    });

    expect($params[$name]['required'])->toBeFalse();
})->with('parameter attributes with a required argument');

it('states a requirement a declaration writes over one nothing proved', function (callable $make, string $in, string $name): void {
    $params = attributeParameters([$make(true)]);

    expect($params[$name]['required'])->toBeTrue();
})->with('parameter attributes with a required argument');

it('says nothing about a parameter it mints from nothing and nobody made a statement about', function (callable $make, string $in, string $name): void {
    // Absent rather than `required: false`: OAS already defaults it, so a member written on every
    // parameter any declaration has ever documented adds bytes and says nothing — the same call
    // `#[ResponseHeader]` makes about the header member it declares.
    $params = attributeParameters([$make(null)]);

    expect($params[$name])->not->toHaveKey('required');
})->with('parameter attributes with a required argument');

it('keeps a path parameter required, having no argument that could say otherwise', function (): void {
    $params = attributeParameters([new PathParameter(name: 'thing', description: 'The thing.')]);

    $arguments = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionClass(PathParameter::class))->getConstructor()?->getParameters() ?? [],
    );

    expect($params['thing']['required'])->toBeTrue()
        ->and($arguments)->not->toContain('required')
        ->and($arguments)->toContain('name');
});

it('leaves a deepObject child requirement standing when a bracketed declaration says nothing', function (): void {
    $params = attributeParameters([new QueryParameter(name: 'filter[status]', format: 'uuid')], deepObjectFilterContainer(...));

    expect($params['filter']['schema']['required'])->toBe(['status']);
});

it('adds a deepObject child to the container required list when a bracketed declaration writes true', function (): void {
    $params = attributeParameters([new QueryParameter(name: 'filter[since]', required: true)], deepObjectFilterContainer(...));

    expect($params['filter']['schema']['required'])->toBe(['status', 'since']);
});

it('takes a deepObject child off the container required list when a bracketed declaration writes false', function (): void {
    // The last member off drops the keyword rather than leaving `required: []`: every other producer of
    // a required list omits it when empty, and the flat and deepObject spellings have to agree.
    $params = attributeParameters([new QueryParameter(name: 'filter[status]', required: false)], deepObjectFilterContainer(...));

    expect($params['filter']['schema'])->not->toHaveKey('required');
});

it('settles each deepObject child separately in one pass', function (): void {
    $params = attributeParameters([
        new QueryParameter(name: 'filter[status]', required: false),
        new QueryParameter(name: 'filter[since]', required: true),
    ], deepObjectFilterContainer(...));

    expect($params['filter']['schema']['required'])->toBe(['since']);
});
