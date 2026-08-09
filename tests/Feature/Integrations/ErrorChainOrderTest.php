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
use Docuccino\Laravel\Integrations\ProblemDetails\ProblemDetailsExceptionToResponse;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Registry\ExtensionRegistry;

/**
 * The error-response chain order is deterministic and load-bearing (design §6, first supports()
 * wins): inferred handler (the app's real shapes) → Problem Details preset → framework-default
 * shapes → terminal fallback. Resolved through the real {@see ExtensionRegistry} so registration
 * order cannot perturb it.
 */
it('resolves the exception mapper chain in the documented tier order', function (): void {
    $resolved = app(ExtensionRegistry::class)->resolve(app(), DefaultExtensions::all(new DocumentConfig('default', [])), []);

    $order = array_map(static fn (object $mapper): string => $mapper::class, $resolved->exceptionToResponse);

    expect($order)->toBe([
        InferredHandlerExceptionToResponse::class,
        ProblemDetailsExceptionToResponse::class,
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

it('cascades past the deferring inferred tier to the problem-details preset, skipping the framework tier', function (): void {
    $resolved = app(ExtensionRegistry::class)->resolve(app(), DefaultExtensions::all(new DocumentConfig('default', [])), []);

    // A document that opted into the Problem Details preset, throwing a framework exception for which
    // no render callback is registered — so the inferred-handler tier has nothing to fold and defers.
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/cascade'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', [], errorResponses: 'problem-details'),
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

    // Runtime first-supports-wins: the winner is the Problem Details preset, and the framework tier
    // sits after it in the chain, so it is never consulted.
    $winner = null;
    $reachedFramework = false;
    foreach ($resolved->exceptionToResponse as $mapper) {
        if ($mapper instanceof FrameworkErrorsExceptionToResponse) {
            $reachedFramework = true;
        }
        if ($mapper->supports($throw, $context)) {
            $winner = $mapper;
            break;
        }
    }

    expect($winner)->toBeInstanceOf(ProblemDetailsExceptionToResponse::class)
        ->and($winner->producer())->toBe('integration:problem-details')
        ->and($reachedFramework)->toBeFalse();
});
