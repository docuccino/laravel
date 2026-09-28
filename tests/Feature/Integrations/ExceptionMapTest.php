<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Integrations\InferredHandler\RenderCallbackDigestContributor;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\DescribedMissingException;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The exception map, stub-side. `Handler::render()` translates a throw through `$exceptions->map(…)` before
 * anything renders it, so the response a route publishes for a mapped throw is the one the TRANSLATION
 * renders to — the workbench form route's missing-model 404 is what each test maps. Whether the real engine
 * reads a mapper's returns from real code is the fixture group's `ExceptionMapTest`.
 */
const MAP_THROWN = ModelNotFoundException::class;

const MAP_FORM_SHOW = 'Workbench\\App\\Http\\Controllers\\FormController::show';

const MAP_FORM_INDEX = 'Workbench\\App\\Http\\Controllers\\FormController::index';

/** The mapper analysis the real engine gives a return building `$fqcn` at `$status`. */
function mapperReturning(string $fqcn, ?int $status): ActionAnalysis
{
    return new ActionAnalysis(
        returns: [new ReturnSite(new ClassT($fqcn), new SourceLocation(''))],
        throws: [new ThrownException($fqcn, $status, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
    );
}

/**
 * The form route's responses, and whether the build reported the map entry unread for the missing model.
 *
 * @param  array<string, ActionAnalysis>  $callables
 * @param  array<string, ActionAnalysis>  $actions
 * @return array{responses: array<string, mixed>, document: array<string, mixed>, deferred: bool, result: GenerationResult}
 */
function mappedFormErrors(array $callables = [], array $actions = []): array
{
    app()->instance(TypeEngine::class, WorkbenchEngine::make($callables, analysisOverrides: $actions));

    $result = generateDocument();
    $document = $result->document->toArray();

    return [
        'responses' => $document['paths']['/api/forms/{form}']['get']['responses'],
        'document' => $document,
        'deferred' => array_filter(
            $result->diagnostics,
            static fn (Diagnostic $d): bool => $d->code === 'inferred-handler.too-dynamic'
                && str_contains($d->message, 'exception map')
                && str_contains($d->message, MAP_THROWN),
        ) !== [],
        'result' => $result,
    ];
}

/** The form route's action, throwing `$fqcn` at `$status` itself rather than through a map. */
function formShowThrowing(string $fqcn, ?int $status): ActionAnalysis
{
    return new ActionAnalysis(
        returns: [new ReturnSite(new ClassT('Workbench\\App\\Data\\FormData'), new SourceLocation(''))],
        throws: [new ThrownException($fqcn, $status, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
    );
}

it('documents a throw as the class a class-string entry translates it to', function (): void {
    registerExceptionMap(MAP_THROWN, AuthorizationException::class, MAP_THROWN);

    $out = mappedFormErrors();

    // `map()` builds the target as `new AuthorizationException('', 0, $e)`, and the handler then prepares that
    // into the 403 it sends. The 404 is the response of a class no one renders any more.
    expect($out['responses'])->toHaveKey('403')->not->toHaveKey('404')
        ->and($out['deferred'])->toBeFalse();
});

it('documents a throw as the exception a closure mapper returns', function (): void {
    $symbol = registerExceptionMap(
        MAP_THROWN,
        static fn (ModelNotFoundException $e) => new HttpException(402, $e->getMessage()),
        MAP_THROWN,
    );

    $out = mappedFormErrors([$symbol => mapperReturning(HttpException::class, 402)]);

    expect($out['responses'])->toHaveKey('402')->not->toHaveKey('404')
        ->and($out['deferred'])->toBeFalse();
});

it('reads the single-closure form, keyed on the closure\'s parameter type', function (): void {
    $symbol = registerExceptionMap(
        static fn (ModelNotFoundException $e) => new HttpException(402, $e->getMessage()),
        null,
        MAP_THROWN,
    );

    $out = mappedFormErrors([$symbol => mapperReturning(HttpException::class, 402)]);

    expect($symbol)->not->toBe('')
        ->and($out['responses'])->toHaveKey('402')->not->toHaveKey('404');
});

it('publishes exactly what the route would if it threw the translation itself', function (): void {
    // The contract: the server renders the translation and never the class thrown, so a mapped throw and a
    // direct throw of what it maps to are one response spelled two ways. The listing route binds no model,
    // so the action's throw is the only error either build has to publish.
    $symbol = registerExceptionMap(
        MAP_THROWN,
        static fn (ModelNotFoundException $e) => new HttpException(402, $e->getMessage()),
        MAP_THROWN,
    );
    $mapped = mappedFormErrors([$symbol => mapperReturning(HttpException::class, 402)], [MAP_FORM_INDEX => formShowThrowing(MAP_THROWN, 404)]);

    $this->refreshApplication();
    $direct = mappedFormErrors(actions: [MAP_FORM_INDEX => formShowThrowing(HttpException::class, 402)]);

    // Every other route's missing model is translated too, so only this route and what it points at compare.
    $listing = static fn (array $out): string => (string) json_encode([
        $out['document']['paths']['/api/forms'],
        resolveResponse($out['document'], $out['document']['paths']['/api/forms']['get']['responses']['402'] ?? null),
    ]);

    expect($mapped['document']['paths']['/api/forms']['get']['responses'])->toHaveKey('402')
        ->and($listing($mapped))->toBe($listing($direct));
});

it('translates a subclass of the class an entry is keyed on', function (): void {
    // `mapException()` asks `is_a()`, so an entry keyed on the parent translates every child thrown.
    registerExceptionMap(RecordsNotFoundException::class, AuthorizationException::class, MAP_THROWN);

    expect(mappedFormErrors()['responses'])->toHaveKey('403')->not->toHaveKey('404');
});

it('leaves a throw no entry is keyed on exactly as it was', function (): void {
    $without = mappedFormErrors()['responses'];

    $this->refreshApplication();
    registerExceptionMap(ConflictHttpException::class, AuthorizationException::class, MAP_THROWN);

    expect(mappedFormErrors()['responses'])->toBe($without);
});

it('matches the class thrown, not the one the handler later prepares it into', function (): void {
    $without = mappedFormErrors()['responses'];

    // `mapException()` runs before `prepareException()`: a missing model is still a ModelNotFoundException
    // when the map is asked, and only becomes a NotFoundHttpException afterwards.
    $this->refreshApplication();
    registerExceptionMap(NotFoundHttpException::class, AuthorizationException::class, MAP_THROWN);

    expect(mappedFormErrors()['responses'])->toBe($without);
});

it('translates by the first entry that matches, in registration order', function (bool $childFirst, string $status): void {
    $entries = [
        [MAP_THROWN, AuthorizationException::class],
        [RecordsNotFoundException::class, AuthenticationException::class],
    ];
    foreach ($childFirst ? $entries : array_reverse($entries) as [$from, $to]) {
        registerExceptionMap($from, $to, MAP_THROWN);
    }

    // Order decides, not specificity: the handler walks its map and stops at the first `is_a()`.
    $responses = mappedFormErrors()['responses'];
    expect($responses)->toHaveKey($status)
        ->and($responses)->not->toHaveKey($status === '403' ? '401' : '403');
})->with([
    'the entry for the thrown class first' => [true, '403'],
    'the entry for its parent first' => [false, '401'],
]);

it('translates once, as mapException() does, so an entry keyed on the translation is never asked', function (): void {
    registerExceptionMap(MAP_THROWN, AuthorizationException::class, MAP_THROWN);
    registerExceptionMap(AuthorizationException::class, AuthenticationException::class, MAP_THROWN);

    // The handler returns the first mapper's answer and renders it; it does not map the answer again.
    expect(mappedFormErrors()['responses'])->toHaveKey('403')->not->toHaveKey('401');
});

it('keeps the thrown answer and says so where a mapper\'s return cannot be read', function (): void {
    $without = mappedFormErrors()['responses'];

    $this->refreshApplication();
    $symbol = registerExceptionMap(MAP_THROWN, static fn (ModelNotFoundException $e) => $e, MAP_THROWN);
    $out = mappedFormErrors([$symbol => new ActionAnalysis(
        returns: [new ReturnSite(new UnknownT('names no exception class'), new SourceLocation(''))],
    )]);

    // Dropping the error would say the route cannot fail, which is false whatever the mapper returns.
    expect($out['responses'])->toBe($without)
        ->and($out['deferred'])->toBeTrue();
});

it('keeps the thrown answer and says so where a mapper answers with more than one exception', function (ActionAnalysis $analysis): void {
    $without = mappedFormErrors()['responses'];

    $this->refreshApplication();
    $symbol = registerExceptionMap(MAP_THROWN, static fn (ModelNotFoundException $e) => $e, MAP_THROWN);
    $out = mappedFormErrors([$symbol => $analysis]);

    expect($out['responses'])->toBe($without)
        ->and($out['deferred'])->toBeTrue();
})->with([
    'two translations' => [new ActionAnalysis(
        returns: [
            new ReturnSite(new ClassT(HttpException::class), new SourceLocation('')),
            new ReturnSite(new ClassT(ConflictHttpException::class), new SourceLocation('')),
        ],
        throws: [
            new ThrownException(HttpException::class, 402, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
            new ThrownException(ConflictHttpException::class, null, [], ThrowConfidence::Certain, ThrowDisposition::Signal),
        ],
    )],
    'a translation beside the throw handed back' => [new ActionAnalysis(
        returns: [
            new ReturnSite(new ClassT(HttpException::class), new SourceLocation('')),
            new ReturnSite(new ClassT(MAP_THROWN), new SourceLocation(''), returnsParameter: 'e'),
        ],
        throws: [new ThrownException(HttpException::class, 402, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
    )],
    'nothing analysable' => [new ActionAnalysis],
]);

it('changes nothing, and says nothing, where every return hands the throw back', function (): void {
    $without = mappedFormErrors()['responses'];

    $this->refreshApplication();
    $symbol = registerExceptionMap(MAP_THROWN, static fn (ModelNotFoundException $e) => $e, MAP_THROWN);
    $out = mappedFormErrors([$symbol => new ActionAnalysis(returns: [
        new ReturnSite(new ClassT(MAP_THROWN), new SourceLocation(''), returnsParameter: 'e'),
    ])]);

    expect($out['responses'])->toBe($without)
        ->and($out['deferred'])->toBeFalse();
});

it('says so where the translation is an HTTP exception whose status nothing read', function (): void {
    $symbol = registerExceptionMap(
        MAP_THROWN,
        static fn (ModelNotFoundException $e) => new HttpException(random_int(400, 499)),
        MAP_THROWN,
    );

    $out = mappedFormErrors([$symbol => mapperReturning(HttpException::class, null)]);

    // The class is read, so the 404 goes; the status is not, so it is filed under the placeholder the
    // other tiers use for an unread one, and the author is told.
    expect($out['responses'])->not->toHaveKey('404')
        ->and($out['deferred'])->toBeTrue();
});

it('says so where a registered mapper has no source to read', function (): void {
    $without = mappedFormErrors()['responses'];

    $this->refreshApplication();
    registerExceptionMap(MAP_THROWN, Closure::fromCallable('strval'), MAP_THROWN);
    $out = mappedFormErrors();

    expect($out['responses'])->toBe($without)
        ->and($out['deferred'])->toBeTrue();
});

it('asks the render callbacks about the translation and never about the class thrown', function (): void {
    registerExceptionMap(MAP_THROWN, AuthorizationException::class, MAP_THROWN);

    // One callback for what the missing model would have been handed, one for what the translation is.
    $source = registerRenderCallback(static fn (NotFoundHttpException $e) => response()->json(['gone' => true], 404), MAP_THROWN);
    $target = registerRenderCallback(static fn (AccessDeniedHttpException $e) => response()->json(['reason' => 'x'], 403), AuthorizationException::class);

    $body = static fn (string $member, int $status): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(
        new ClassT('Illuminate\\Http\\JsonResponse', [new ArrayShapeT([new ArrayShapeField($member, ScalarT::string())]), new LiteralT($status)]),
        new SourceLocation(''),
    )]);
    $out = mappedFormErrors([$source => $body('gone', 404), $target => $body('reason', 403)]);

    expect($out['responses'])->not->toHaveKey('404')
        ->and(errorSchemaOf($out['document'], '403', 'application/json')['properties'] ?? [])->toHaveKey('reason');
});

it('hands the respond() callback the translation', function (): void {
    registerExceptionMap(MAP_THROWN, AuthorizationException::class, MAP_THROWN);
    $respond = registerRespondCallback(
        static fn (Response $response, Throwable $e, Request $request): Response => $response,
        AuthorizationException::class,
    );

    $problem = new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([new ArrayShapeField('title', ScalarT::string())]),
        new UnknownT('status not folded'),
        new LiteralT('application/problem+json'),
    ]);
    $out = mappedFormErrors([$respond => new ActionAnalysis(returns: [new ReturnSite($problem, new SourceLocation(''))])]);

    expect(array_keys(resolveResponse($out['document'], $out['responses']['403'])['content'] ?? []))
        ->toBe(['application/problem+json']);
});

it('translates the responses the framework raises implicitly too', function (): void {
    $implicit = static function (array $document): array {
        $found = [];
        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (isset($operation['responses']['422'])) {
                    $found[] = [$path, $method];
                }
            }
        }

        return $found;
    };

    $without = mappedFormErrors()['document'];
    $validated = $implicit($without);

    $this->refreshApplication();
    $symbol = registerExceptionMap(
        ValidationException::class,
        static fn (ValidationException $e) => new HttpException(400, $e->getMessage()),
        ValidationException::class,
    );
    $document = mappedFormErrors([$symbol => mapperReturning(HttpException::class, 400)])['document'];

    expect($validated)->not->toBe([])
        ->and($implicit($document))->toBe([]);
    foreach ($validated as [$path, $method]) {
        expect($document['paths'][$path][$method]['responses'])->toHaveKey('400');
    }
});

it('names the body after the class that renders it, not the class thrown', function (): void {
    // Thrown as a declared class, translated to one that declares nothing: the name described a body the
    // server no longer sends.
    registerExceptionMap(DescribedMissingException::class, AuthorizationException::class, DescribedMissingException::class);
    $translatedAway = mappedFormErrors(actions: [MAP_FORM_SHOW => formShowThrowing(DescribedMissingException::class, 500)])['document'];

    $this->refreshApplication();
    // Thrown as a class that declares nothing, translated to the declared one: its name is the body's.
    registerExceptionMap(MAP_THROWN, DescribedMissingException::class, MAP_THROWN);
    $translatedTo = mappedFormErrors()['document'];

    expect(json_encode($translatedAway['components'] ?? []))->not->toContain('ResourceMissing')
        ->and(array_keys($translatedTo['components']['responses'] ?? []))->toContain('ResourceMissing');
});

it('re-documents the tier when an entry is added, re-pointed or removed', function (): void {
    $digest = static fn (): string => app(RenderCallbackDigestContributor::class)->digest();

    $none = $digest();
    registerExceptionMap(MAP_THROWN, AuthorizationException::class, MAP_THROWN);
    $one = $digest();

    $this->refreshApplication();
    registerExceptionMap(MAP_THROWN, AuthenticationException::class, MAP_THROWN);
    $repointed = $digest();

    expect($one)->not->toBe($none)
        ->and($repointed)->not->toBe($one);
});
