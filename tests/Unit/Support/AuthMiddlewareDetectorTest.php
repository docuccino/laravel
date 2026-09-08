<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Support\AuthMiddlewareDetector;
use Illuminate\Auth\Middleware\Authenticate;

/**
 * The `security.auto_detect_middleware` pattern, over the whole grammar it is written in. Every row is
 * asserted for BOTH spellings of the same middleware against one expectation, because a pattern that
 * means different things for `auth:web` and for `Authenticate::using('web')` is the defect this reader
 * exists to prevent — and the pattern decides the implicit 401 and the security requirement together.
 */
it('reads the configured pattern the same way for either spelling of one middleware', function (string $pattern, bool $matched): void {
    $context = static fn (string $middleware): RouteContext => new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/things', middleware: [$middleware]),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet([]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', [], authMiddleware: $pattern),
    );

    expect(AuthMiddlewareDetector::matches($context('auth:web')))->toBe($matched)
        ->and(AuthMiddlewareDetector::matches($context(Authenticate::using('web'))))->toBe($matched);
})->with([
    'the shipped default' => ['auth*', true],
    'narrowed to the guard by hand' => ['auth:web', true],
    'narrowed to another guard' => ['auth:api', false],
    'the alias with no wildcard' => ['auth', false],
    // A pattern naming the class, which is the one kind whose subject is full of separators. `fnmatch()`
    // reads `\` in a PATTERN as an escape character, so this matched nothing at all until the pattern
    // was read with the product's own wildcard grammar.
    'the class name, wildcarded' => ['Illuminate\\Auth\\Middleware\\Authenticate*', true],
    'the class name, exactly' => ['Illuminate\\Auth\\Middleware\\Authenticate:web', true],
    'a class name that is only a prefix of the real one' => ['Illuminate\\Auth\\Middleware\\Authentic*', true],
    'another middleware\'s class name' => ['Illuminate\\Auth\\Middleware\\Authorize*', false],
    'a pattern matching nothing on the route' => ['tenant*', false],
]);

it('detects nothing where the document configures no pattern', function (?string $pattern): void {
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/things', middleware: ['auth:web']),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet([]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', [], authMiddleware: $pattern),
    );

    expect(AuthMiddlewareDetector::matches($context))->toBeFalse();
})->with([
    'no pattern at all' => [null],
    'an empty pattern' => [''],
]);
