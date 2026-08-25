<?php

declare(strict_types=1);

use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Support\IgnoredResponses;

/**
 * Exactly what a discarded mapping leaves behind, at the seam that discards it.
 *
 * `mapThrow()` asks a mapper what the response WOULD be so it can read the status the mapper landed on,
 * then throws the answer away when the route drops that status. Three kinds of thing get written on the
 * way, and each has its own answer: components and their diagnostics go back (they describe a body nobody
 * will see), route notes go back with them (same fact, another road into the document), and dependency
 * files stay (the answer is a function of them, dropped or not).
 */

/** A mapper that writes one of each kind and answers at $status. */
function rollbackWritingMapper(string $status): ExceptionToResponse
{
    return new class($status) implements ExceptionToResponse
    {
        public function __construct(private readonly string $status) {}

        public function supports(ThrownException $exception, RouteContext $context): bool
        {
            return true;
        }

        public function producer(): string
        {
            return 'integration:acme';
        }

        public function toResponse(ThrownException $exception, RouteContext $context, ComponentRegistry $components): ?ResponseDraft
        {
            $by = Contribution::integration('acme');

            $components->registerSchema('Discarded', ['type' => 'object'], 'schema:discarded');
            $components->addDiagnostic(new Diagnostic(Severity::Warning, 'acme.about-the-body', 'The body it built.'));
            $context->notes()->record('acme.channel', 'App\\Renderer::__invoke', $exception->exceptionFqcn);
            $context->recordDependencyFiles(['/app/Exceptions/Renderer.php']);

            return new ResponseDraft($this->status);
        }
    };
}

/** A route dropping 409, with the writing mapper as its only exception tier. */
function rollbackContext(string $status): RouteContext
{
    $attributes = new AttributeSet;
    $attributes->add(new IgnoreResponse(409));

    return new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: new ActionRef('/app/Http/GadgetController.php', 'App\\Http\\GadgetController', 'index'),
        attributes: $attributes,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(exceptionToResponse: [rollbackWritingMapper($status)]),
    );
}

function rollbackThrow(): ThrownException
{
    return new ThrownException(
        'App\\Exceptions\\ThingMissing',
        409,
        [],
        ThrowConfidence::Certain,
        ThrowDisposition::Signal,
    );
}

it('leaves nothing behind when the route drops the status the mapper landed on', function (): void {
    $context = rollbackContext('409');

    expect(IgnoredResponses::mapThrow($context, rollbackThrow()))->toBeNull()
        // A body converted only to be discarded hoists no component and leaks no name reservation…
        ->and($context->components->schemas())->toBe([])
        // …and says nothing about a body nobody will see.
        ->and($context->components->diagnostics())->toBe([])
        // The note is the same kind of fact by another road: it rides the route's fragment into a
        // document-level summary, so left standing it asks the author to fix a response the document does
        // not publish — a diagnostic firing exactly where there is nothing to do.
        ->and($context->notes()->all())->toBe([]);
});

it('keeps the files the discarded mapping read', function (): void {
    // Deliberately NOT rolled back. The mapper read those to reach its answer, and "and it was dropped"
    // is as much a function of them as a published body would be — so a fragment that stopped re-hashing
    // them would serve a stale decision after the file that drove it changed. Over-keying costs a
    // rebuild; under-keying costs correctness.
    $context = rollbackContext('409');

    IgnoredResponses::mapThrow($context, rollbackThrow());

    expect($context->dependencyFiles())->toContain('/app/Exceptions/Renderer.php');
});

it('leaves everything standing when the route drops some other status', function (): void {
    // The rollback's own failure mode: a mapping that is KEPT must keep its components, its diagnostics
    // and its notes, or the fix silences the reports it was meant to place correctly.
    $context = rollbackContext('404');

    $mapped = IgnoredResponses::mapThrow($context, rollbackThrow());

    expect($mapped?->draft->status)->toBe('404')
        ->and(array_keys($context->components->schemas()))->toBe(['Discarded'])
        ->and(array_map(static fn (Diagnostic $d): string => $d->code, $context->components->diagnostics()))
        ->toBe(['acme.about-the-body'])
        ->and($context->notes()->all())->toBe(['acme.channel' => ['App\\Renderer::__invoke' => ['App\\Exceptions\\ThingMissing']]])
        ->and($context->dependencyFiles())->toContain('/app/Exceptions/Renderer.php');
});

it('reads the ignore off the mapped status and not off the throw', function (): void {
    // A mapper answers at the status its own tier proves, which is not always the one the analysis
    // attached to the throw — so a throw carrying 409 that maps to 404 is published, and an attribute
    // naming 404 is what drops it.
    $attributes = new AttributeSet;
    $attributes->add(new IgnoreResponse(404));

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: new ActionRef('/app/Http/GadgetController.php', 'App\\Http\\GadgetController', 'index'),
        attributes: $attributes,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(exceptionToResponse: [rollbackWritingMapper('404')]),
    );

    expect(IgnoredResponses::mapThrow($context, rollbackThrow()))->toBeNull()
        ->and($context->notes()->all())->toBe([]);
});

it('answers null with nothing to roll back when no mapper claims the throw', function (): void {
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: new ActionRef('/app/Http/GadgetController.php', 'App\\Http\\GadgetController', 'index'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
    );

    expect(IgnoredResponses::mapThrow($context, rollbackThrow()))->toBeNull()
        ->and($context->notes()->all())->toBe([]);
});
