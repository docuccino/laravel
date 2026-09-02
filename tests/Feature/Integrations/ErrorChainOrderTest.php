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
use Docuccino\Laravel\Integrations\InferredHandler\InferredHandlerExceptionToResponse;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Registry\ExtensionRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The error-response chain order is deterministic and load-bearing (design §6, first supports()
 * wins): inferred handler (the app's real shapes) → framework-default shapes → terminal fallback.
 * Resolved through the real {@see ExtensionRegistry} so registration order cannot perturb it.
 */
it('resolves the exception mapper chain in the documented tier order', function (): void {
    $resolved = app(ExtensionRegistry::class)->resolve(app(), DefaultExtensions::all(new DocumentConfig('default', [])), []);

    $order = array_map(static fn (object $mapper): string => $mapper::class, $resolved->exceptionToResponse);

    expect($order)->toBe([
        InferredHandlerExceptionToResponse::class,
        FrameworkErrorsExceptionToResponse::class,
        DefaultExceptionToResponse::class,
    ]);
});

it('emits the shared 401 reason phrase Unauthorized from the terminal fallback tier', function (): void {
    // The historical drift: this fallback said "Unauthenticated" while the shared table says
    // "Unauthorized". It now reads the table, so 401 is "Unauthorized" everywhere.
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/fallback-401'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
    );
    $throw = new ThrownException(
        'RuntimeException',
        401,
        [],
        ThrowConfidence::Certain,
        ThrowDisposition::Signal,
    );

    $draft = (new DefaultExceptionToResponse)->toResponse($throw, $context, new ComponentRegistry);

    expect($draft->status)->toBe('401')
        ->and($draft->resolvedField('description'))->toBe('Unauthorized');
});

it('keys an unread status at the exception’s classification, exactly as the tier ahead of it would', function (string $fqcn, string $status): void {
    // A status nothing read still needs a key, and this tier is the one that has to invent it. Inventing
    // its own would be a second answer to a question the shared table already answers — and two tiers
    // keying one error differently publish two responses where the server sends one, which is the whole
    // reason that table exists. Stated as the classification each exception carries, not as the number
    // this tier happens to reach for.
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/unread-status'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
    );
    $throw = new ThrownException($fqcn, null, [], ThrowConfidence::Certain, ThrowDisposition::Signal);

    expect((new DefaultExceptionToResponse)->toResponse($throw, $context, new ComponentRegistry)->status)->toBe($status);
})->with([
    // The framework tier ordinarily answers for a mapped exception first, so this is what the document
    // says when an application turns that integration off — and it agrees with what it would have said.
    'a mapped exception classifies' => [ModelNotFoundException::class, '404'],
    // Outside the table there is no classification, only the key the document cannot do without.
    'an application exception takes the unplaced status' => ['App\\Exceptions\\ProbeFailure', '500'],
]);

it('cascades past the deferring inferred tier to the framework tier, never reaching the fallback', function (): void {
    $resolved = app(ExtensionRegistry::class)->resolve(app(), DefaultExtensions::all(new DocumentConfig('default', [])), []);

    // A framework exception for which no render callback is registered — so the inferred-handler tier has
    // nothing to fold and defers, and the tier behind it answers.
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/cascade'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', [], errorResponses: 'default'),
    );
    $throw = new ThrownException(
        'Illuminate\\Validation\\ValidationException',
        422,
        [],
        ThrowConfidence::Certain,
        ThrowDisposition::Signal,
    );

    // The inferred-handler tier (first in the chain) genuinely defers for this exception.
    $inferred = array_values(array_filter(
        $resolved->exceptionToResponse,
        static fn (object $m): bool => $m instanceof InferredHandlerExceptionToResponse,
    ))[0];
    expect($inferred->supports($throw, $context))->toBeFalse();

    // Runtime first-supports-wins: the winner is the framework tier, and the terminal fallback sits after
    // it in the chain, so it is never consulted for an exception the table knows.
    $winner = null;
    $reachedFallback = false;
    foreach ($resolved->exceptionToResponse as $mapper) {
        if ($mapper instanceof DefaultExceptionToResponse) {
            $reachedFallback = true;
        }
        if ($mapper->supports($throw, $context)) {
            $winner = $mapper;
            break;
        }
    }

    expect($winner)->toBeInstanceOf(FrameworkErrorsExceptionToResponse::class)
        ->and($winner->producer())->toBe('integration:framework-errors')
        ->and($reachedFallback)->toBeFalse();
});
