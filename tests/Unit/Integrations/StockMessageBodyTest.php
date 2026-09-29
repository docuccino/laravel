<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Laravel\Exceptions\DefaultExceptionToResponse;
use Docuccino\Laravel\Integrations\FrameworkErrors\FrameworkErrorsExceptionToResponse;
use Docuccino\Laravel\Integrations\RateLimit\RateLimitResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Laravel's stock JSON error body, stated by three producers: the framework-errors tier, the terminal
 * fallback and the rate-limit 429's own fallback. All three describe one renderer, so they publish one
 * schema — and where they did not, the same body under one status contested one name and lost it.
 *
 * The schema is stated here from the renderer rather than read off any producer: `convertExceptionToArray()`
 * writes `message` in both of its branches — the debug array and the production one — for an
 * `HttpException` of any status and for every other throwable alike, so `message` is always present.
 */
const STOCK_MESSAGE_BODY = [
    'type' => 'object',
    'properties' => ['message' => ['type' => 'string']],
    'required' => ['message'],
];

it('publishes the stock body identically from every producer of it', function (string $producer): void {
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/stock-body'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
    );

    $schema = match ($producer) {
        'framework-errors' => FrameworkErrorsExceptionToResponse::table()[NotFoundHttpException::class]['shape'],
        'fallback' => (new DefaultExceptionToResponse)->toResponse(
            new ThrownException(HttpException::class, 501, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
            $context,
            new ComponentRegistry,
        )->freeze()->toArray()['content']['application/json']['schema'],
        'rate-limit' => (new RateLimitResponse)->build()['content']['application/json']['schema'],
    };

    expect(array_diff_key((array) $schema, ['x-docuccino' => true]))->toEqualCanonicalizing(STOCK_MESSAGE_BODY)
        ->and($schema['required'] ?? null)->toBe(['message']);
})->with(['framework-errors', 'fallback', 'rate-limit']);
