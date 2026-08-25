<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Support\FrameworkClasses;

/**
 * The real-analyser half of the framework-response guard: proof that each class it names is one the
 * engine actually hands back, as a bare `ClassT` with no payload generic, for an idiomatic action.
 * Without this the guard list would be a claim; here every entry is a fact about the fixture app.
 *
 * Two entries are not asserted below — the Symfony `Response` and `RedirectResponse` bases. They are
 * named for the subclass checks to match against (every entry that IS asserted extends one of them),
 * and are covered as return types by FrameworkResponseTest's subclass case.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('recovers each guarded framework response class from an idiomatic action', function (
    string $relPath,
    string $class,
    string $method,
    string $expected,
): void {
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze($relPath, $class, $method));
    $returnType = $analysis->returns[0]->type ?? null;

    expect($returnType)->toBeInstanceOf(ClassT::class)
        ->and($returnType->fqcn)->toBe($expected)
        ->and($returnType->typeArgs)->toBe([])
        ->and(FrameworkClasses::RESPONSE_CLASSES)->toContain($expected)
        ->and(FrameworkClasses::isResponse($expected))->toBeTrue();
})->with([
    'json' => [
        'app/Http/Controllers/SsoRedirectController.php',
        'App\\Http\\Controllers\\SsoRedirectController',
        'introspect',
        'Illuminate\\Http\\JsonResponse',
    ],
    'redirect' => [
        'app/Http/Controllers/SsoRedirectController.php',
        'App\\Http\\Controllers\\SsoRedirectController',
        'connection',
        'Illuminate\\Http\\RedirectResponse',
    ],
    'download' => [
        'app/Http/Controllers/FileDeliveryController.php',
        'App\\Http\\Controllers\\FileDeliveryController',
        'download',
        'Symfony\\Component\\HttpFoundation\\BinaryFileResponse',
    ],
    'stream' => [
        'app/Http/Controllers/FileDeliveryController.php',
        'App\\Http\\Controllers\\FileDeliveryController',
        'export',
        'Symfony\\Component\\HttpFoundation\\StreamedResponse',
    ],
    'plain' => [
        'app/Http/Controllers/FileDeliveryController.php',
        'App\\Http\\Controllers\\FileDeliveryController',
        'health',
        'Illuminate\\Http\\Response',
    ],
])->group('fixture');

it('keeps a really-recovered RedirectResponse out of components and documents it as a redirect', function (): void {
    // The end-to-end version of the guard, over the type the analyser genuinely produced and the
    // metadata it genuinely reflects — `original`/`exception`/`headers`, which is what an unguarded
    // chain hoists as a component and references as the 200 body. A redirect carries no JSON at all,
    // so what lands is a 3xx with a Location header and nothing else.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/SsoRedirectController.php',
        'App\\Http\\Controllers\\SsoRedirectController',
        'connection',
    ));
    $returnType = $analysis->returns[0]->type ?? null;
    $fqcn = 'Illuminate\\Http\\RedirectResponse';

    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata($fqcn));
    expect(array_map(static fn ($p): string => $p->name, $metadata->properties))
        ->toContain('original', 'headers');

    [$responses, $document] = documentForReturn($returnType, [$fqcn => $metadata]);

    expect(array_keys($responses))->toBe(['3XX'])
        ->and($responses['3XX'])->not->toHaveKey('content')
        ->and($responses['3XX']['headers'])->toHaveKey('Location')
        ->and(typeSchemas($document))->toBe([]);
})->group('fixture');

it('keeps a really-recovered bare JsonResponse out of components and says the body is unrecovered', function (): void {
    // Nothing at the call site names a payload — the collaborator is a CONTRACT, so there is no body to
    // follow — so the 200 documents an OPEN application/json body rather than the response object's
    // members, and the loss is announced instead of passing for a deliberate shapeless body.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/SsoRedirectController.php',
        'App\\Http\\Controllers\\SsoRedirectController',
        'introspect',
    ));
    $returnType = $analysis->returns[0]->type ?? null;
    $fqcn = 'Illuminate\\Http\\JsonResponse';

    [$responses, $document, $diagnostics] = documentForReturn(
        $returnType,
        [$fqcn => ClassMetadata::fromArray(FixtureRunner::classMetadata($fqcn))],
    );

    expect($responses['200']['content']['application/json']['schema'])->toBe([])
        ->and(typeSchemas($document))->toBe([])
        ->and(array_map(static fn ($d): string => $d->code, $diagnostics))
        ->toContain('inferred-response.payload-unrecoverable');
})->group('fixture');

it('says the body is unrecovered when a fluent status was the only thing recovered', function (): void {
    // `reset` stamps a status onto a response whose body nothing names, so the engine answers with a
    // status and an UNRESOLVED payload. Parameterised is not the same as recovered: the notice has to
    // fire here exactly as it does above, or a `->setStatusCode()` would buy the response a clean bill
    // while leaving the consumer with the same open `{}`.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/SsoRedirectController.php',
        'App\\Http\\Controllers\\SsoRedirectController',
        'reset',
    ));
    $returnType = $analysis->returns[0]->type ?? null;
    $fqcn = 'Illuminate\\Http\\JsonResponse';

    expect($returnType)->toBeInstanceOf(ClassT::class)
        ->and($returnType->typeArgs[0] ?? null)->toBeInstanceOf(UnknownT::class)
        ->and($returnType->typeArgs[1] ?? null)->toEqual(new LiteralT(200));

    [$responses, $document, $diagnostics] = documentForReturn(
        $returnType,
        [$fqcn => ClassMetadata::fromArray(FixtureRunner::classMetadata($fqcn))],
    );

    expect($responses['200']['content']['application/json']['schema'])->toBe([])
        ->and(typeSchemas($document))->toBe([])
        ->and(array_map(static fn ($d): string => $d->code, $diagnostics))
        ->toContain('inferred-response.payload-unrecoverable');
})->group('fixture');
