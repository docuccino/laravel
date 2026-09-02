<?php

declare(strict_types=1);

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Exceptions\DefaultExceptionToResponse;
use Docuccino\Laravel\Integrations\FrameworkErrors\FrameworkErrorsExceptionToResponse;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerResponseBuilder;
use Docuccino\Laravel\Integrations\InferredHandler\InferredHandlerExceptionToResponse;
use Docuccino\Laravel\Integrations\RateLimit\RateLimitResponsesExtension;
use Docuccino\Laravel\Integrations\Support\AppRenderedErrors;
use Docuccino\Laravel\Tests\Fixtures\InferredHandler\ProbeRejection;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Two TIERS publish a body that is the FRAMEWORK's rather than the application's — the framework-defaults
 * shapes and the terminal fallback's `{message}` — and both stand aside from the BODY (never the status)
 * where the build watched the application's own handler render the exception and could not read what it
 * rendered it to.
 *
 * The gate is what this file is about, in both directions and over both tiers, because a gate that never
 * closes publishes a shape the server does not send and a gate that never opens strips the error contract
 * off every application that has no custom handler at all — which is the population those tiers exist for.
 *
 * They are not the whole of the domain, though, which is the last test here: the rate-limit 429 fills the
 * same gap with the same `{message}` from outside this interface entirely, and is gated with them.
 */
function gateContext(string $errorResponses = 'default', ?TypeEngine $engine = null): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/probe'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet,
        engine: $engine ?? new NullTypeEngine,
        document: new DocumentConfig('default', [], errorResponses: $errorResponses),
    );
}

function gateThrow(string $fqcn, ?int $status = null): ThrownException
{
    return new ThrownException($fqcn, $status, [], ThrowConfidence::Certain, ThrowDisposition::Signal);
}

/**
 * Both tiers that speak for the framework, with an exception each reaches: the framework-defaults tier
 * answers for a mapped exception, and the fallback for one no table knows. Asserting them as one dataset
 * is what stops the two halves of the rule from being covered separately and diverging in the middle.
 *
 * @return array<string, array{0: ExceptionToResponse, 1: ThrownException, 2: string}>
 */
function gatedTiers(): array
{
    return [
        'framework-defaults' => [new FrameworkErrorsExceptionToResponse, gateThrow(ModelNotFoundException::class), '404'],
        'terminal fallback' => [new DefaultExceptionToResponse, gateThrow(ProbeRejection::class), '500'],
    ];
}

it('publishes the framework body where nothing says the application replaced it', function (ExceptionToResponse $tier, ThrownException $throw, string $status): void {
    // The gate OPEN. An application with no custom handler — or one whose renderer hands the throwable
    // back to the framework — has exactly this context: no note, so the framework's own shape stands, and
    // it is stated here in full rather than probed for a key, because this is the contract that must not
    // move for the population these tiers exist for.
    $draft = $tier->toResponse($throw, gateContext(), new ComponentRegistry);

    expect($draft?->status)->toBe($status);

    $schema = $draft?->freeze()->content['application/json']['schema'] ?? [];
    expect($schema['type'] ?? null)->toBe('object')
        ->and($schema['properties'] ?? null)->toBe(['message' => ['type' => 'string']]);
})->with(gatedTiers());

it('states the status and nothing else where the application demonstrably renders the exception', function (ExceptionToResponse $tier, ThrownException $throw, string $status): void {
    // The gate CLOSED. The status is the framework's own classification and stays; the body is the one
    // thing the renderer replaced, so it goes unsaid rather than being asserted over code that refutes it.
    $context = gateContext();
    AppRenderedErrors::record($context, $throw->exceptionFqcn, 'App\\Exceptions\\Handler::render');

    $draft = $tier->toResponse($throw, $context, new ComponentRegistry);
    $frozen = $draft?->freeze()->toArray() ?? [];

    expect($draft?->status)->toBe($status)
        // `description` present is what proves the response is really there and only its body was withheld.
        ->and($frozen)->toHaveKey('description')
        ->and($frozen)->not->toHaveKey('content')
        // Nor a shared component name for a body nobody published — two errors would then meet on one name.
        ->and($draft?->componentClaim())->toBeNull();
})->with(gatedTiers());

it('keys the note by the exact exception, so one throw’s renderer never silences another’s', function (): void {
    $context = gateContext();
    AppRenderedErrors::record($context, ProbeRejection::class, 'App\\Exceptions\\Handler::render');

    expect(AppRenderedErrors::includes($context, ProbeRejection::class))->toBeTrue()
        ->and(AppRenderedErrors::includes($context, ModelNotFoundException::class))->toBeFalse();

    // The framework tier answering for a DIFFERENT exception on the same route keeps its body.
    $draft = (new FrameworkErrorsExceptionToResponse)->toResponse(gateThrow(ModelNotFoundException::class), $context, new ComponentRegistry);
    expect($draft?->freeze()->content['application/json']['schema']['properties'] ?? null)
        ->toBe(['message' => ['type' => 'string']]);
});

it('divides every producer of a framework-shaped error body between writing the note and reading it', function (): void {
    // The rows above are a SUBSET guard, and a subset guard is silent outside itself. The subset that had
    // been asserted was one INTERFACE, and the fourth producer of a framework-shaped error body is not a
    // mapper at all: the rate-limit 429 asks the chain and fills the gap from Laravel's own `{message}`,
    // which is the same claim the two tiers here withhold. So the domain is the UNION of the two ways a
    // class can come to publish that body — implementing the chain's contract, and reaching for the shared
    // framework error table — and a member owing no answer carries a row rather than falling in the gap.
    $readers = [];
    $writers = [];
    $neither = [];

    foreach (adapterFrameworkErrorProducers() as $fqcn => $source) {
        $reads = str_contains($source, 'AppRenderedErrors::includes');
        $writes = str_contains($source, 'AppRenderedErrors::record');

        match (true) {
            $reads => $readers[] = $fqcn,
            $writes => $writers[] = $fqcn,
            default => $neither[] = $fqcn,
        };
    }

    // Not a floor: this population is five, so no number "well under what the tree holds" is far enough
    // above zero to mean anything, and the three exact assertions below already fail on a scan that lost
    // a member or found none. What is owed instead is the PARTITION — every producer the scan found lands
    // in exactly one bucket, so a classification that answered twice or dropped one shows up here rather
    // than as a bucket that quietly agrees with a shorter list. The scan's own denominator is guarded
    // separately, by the test below.
    expect(array_merge($readers, $writers, $neither))
        ->toHaveCount(count(adapterFrameworkErrorProducers()));

    sort($readers);
    $gated = array_map(static fn (array $row): string => $row[0]::class, array_values(gatedTiers()));
    // The 429 is gated in the same direction and off the same note, through its own entry point rather
    // than the chain's — `RateLimitTest` drives both directions of it.
    $gated[] = RateLimitResponsesExtension::class;
    sort($gated);

    expect($readers)->toBe($gated)
        // The inferred-handler tier is the only writer: it is the one that watched the renderer.
        ->and($writers)->toBe([InferredHandlerExceptionToResponse::class])
        // A producer publishing a body of the APPLICATION's own is not the framework speaking and owes no
        // gate — but it owes a row here saying so, rather than being silently uncovered. The builder reads
        // the shared table for a status key and a reason phrase and never for a body.
        ->and($neither)->toBe([HandlerResponseBuilder::class]);
});

it('recognises every class the adapter declares, so none can hide behind a modifier', function (): void {
    // The union of the two derivations above is only as wide as the set of files the scan can name a class
    // in, and nothing was asking how wide THAT was: the pattern accepted `final class` and no other
    // modifier, so `final readonly class` and `abstract class` — 56 files — were outside the guard for the
    // shape of their declaration. A producer landing in one of them would have been silently uncovered.
    //
    // The oracle is PHP's own tokenizer rather than a second regex, so it states the rule independently:
    // a guard that asks the pattern for its own answer agrees with whatever the pattern does. Anonymous
    // classes and `Foo::class` are not declarations and are excluded on the token stream, not by pattern.
    $root = dirname(__DIR__, 3).'/src';
    $missed = [];
    $declared = 0;

    /** @var iterable<SplFileInfo> $entries */
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($entries as $entry) {
        if (! $entry->isFile() || $entry->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($entry->getPathname());
        if (! adapterDeclaresNamedClass($source)) {
            continue;
        }

        $declared++;
        if (preg_match(adapterClassPattern().'/m', $source) !== 1) {
            $missed[] = substr($entry->getPathname(), strlen($root) + 1);
        }
    }

    sort($missed);

    // Well under what the tree holds, and far above zero: a tokenizer walk that stopped recognising a
    // class declaration would otherwise report perfect agreement over an empty set.
    expect($declared)->toBeGreaterThan(100)
        ->and($missed)->toBe([]);
});

/**
 * Whether $source declares a named class, read off PHP's token stream: a `T_CLASS` that is neither the
 * `::class` constant nor an anonymous `new class`. The independent statement of what the scan's pattern
 * has to recognise.
 */
function adapterDeclaresNamedClass(string $source): bool
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn (array|string $token): bool => is_string($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    foreach ($tokens as $index => $token) {
        if (is_string($token) || $token[0] !== T_CLASS) {
            continue;
        }

        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;

        // `Foo::class` puts a `::` in front of it; `new class(...) {}` has no name after it.
        if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
            continue;
        }
        if (is_array($next) && $next[0] === T_STRING) {
            return true;
        }
    }

    return false;
}

/**
 * The declaration of a class, with every modifier PHP lets sit in front of one. `final readonly class`
 * and `abstract class` are both ordinary here — 56 of the adapter's classes carry one — and a pattern
 * matching only `final` reads straight past them, which would have left the scan below blind to a
 * producer for the shape of its declaration rather than for anything it does.
 */
function adapterClassPattern(string $tail = ''): string
{
    return '/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)'.$tail;
}

/**
 * Every class in the adapter that can come to publish a framework-shaped error body, as FQCN => its
 * source: the ones implementing the error chain's contract, and the ones reaching for the shared framework
 * exception table. A source scan rather than a reflection sweep, because the classification above is about
 * which call each one makes — and a UNION of two derivations, because either alone leaves the other's
 * members silently uncovered.
 *
 * @return array<class-string, string>
 */
function adapterFrameworkErrorProducers(): array
{
    $root = dirname(__DIR__, 3).'/src';
    $found = [];

    /** @var iterable<SplFileInfo> $entries */
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($entries as $entry) {
        if (! $entry->isFile() || $entry->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($entry->getPathname());
        if (! preg_match(adapterClassPattern().'/m', $source, $class)) {
            continue;
        }

        $implements = (bool) preg_match(adapterClassPattern('[^{]*\bimplements\b[^{]*\bExceptionToResponse\b').'/m', $source);
        if (! $implements && ! str_contains($source, 'FrameworkExceptionTable::')) {
            continue;
        }
        if (! preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)) {
            continue;
        }

        /** @var class-string $fqcn */
        $fqcn = trim($namespace[1]).'\\'.$class[1];
        $found[$fqcn] = $source;
    }

    ksort($found);

    return $found;
}

/**
 * The write side, through the real tier: which renderers count as the application answering for an
 * exception. Only one that RETURNED something and did not hand the type back to the framework — anything
 * else leaves the framework default the best answer anyone has.
 */
it('records the note only for a renderer that returned something of its own', function (ActionAnalysis $analysis, bool $recorded): void {
    $symbol = registerRenderCallback(
        static fn (ProbeRejection $e) => response()->json(['title' => 'Nope'], 400),
        ProbeRejection::class,
    );

    $context = gateContext('default', WorkbenchEngine::make([$symbol => $analysis]));
    app(InferredHandlerExceptionToResponse::class)->toResponse(gateThrow(ProbeRejection::class), $context, new ComponentRegistry);

    expect(AppRenderedErrors::includes($context, ProbeRejection::class))->toBe($recorded);
})->with([
    // A response of its own the build could not read: the application has demonstrably replaced the shape.
    'an unreadable response' => [new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\Response'), new SourceLocation(''))]), true],
    // `return null` / a void arm: the renderer hands the throwable back, so the framework really does
    // render it and its default is the truth.
    'a null delegation' => [new ActionAnalysis(returns: [new ReturnSite(new NullT, new SourceLocation(''))]), false],
    'a void delegation' => [new ActionAnalysis(returns: [new ReturnSite(new VoidT, new SourceLocation(''))]), false],
    // Nothing recovered at all refutes nothing — the gate keys on a renderer that says otherwise, never on
    // a fold that failed.
    'nothing recovered' => [new ActionAnalysis, false],
]);

it('leaves an application with no handler at all untouched, through the whole pipeline', function (): void {
    // The population the framework tier exists for, byte for byte and end to end: no render callback is
    // registered, so nothing is ever recorded and the stock body is published exactly as before.
    bindStubEngine();

    $document = generateDocument()->document->toArray();
    $response = $document['paths']['/api/forms/{form}']['get']['responses']['404'] ?? [];
    $schema = resolveSchema($document, resolveResponse($document, $response)['content']['application/json']['schema'] ?? []);

    expect($schema['type'] ?? null)->toBe('object')
        ->and($schema['properties'] ?? null)->toBe(['message' => ['type' => 'string']])
        ->and($schema['required'] ?? null)->toBe(['message']);
});

/** A renderer for the whole route set, so the pipeline tests below differ only in what it renders TO. */
function renderedProbeDocument(ReturnSite ...$returns): array
{
    $symbol = registerRenderCallback(
        static fn (ProbeRejection $e) => response()->json(['title' => 'Nope'], 400),
        ProbeRejection::class,
    );

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        [$symbol => new ActionAnalysis(returns: $returns)],
        analysisOverrides: [
            'Workbench\\App\\Http\\Controllers\\FormController::show' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Workbench\\App\\Data\\FormData'), new SourceLocation(''))],
                throws: [gateThrow(ProbeRejection::class)],
            ),
        ],
    ));

    return generateDocument()->document->toArray();
}

it('withholds the fallback body for an unmapped exception the application renders itself', function (): void {
    // The sibling of the framework-defaults case: an exception no table knows reaches the terminal tier,
    // and its generic `{message}` is the framework's shape just the same.
    $document = renderedProbeDocument(new ReturnSite(new ClassT('Illuminate\\Http\\Response'), new SourceLocation('')));
    $responses = $document['paths']['/api/forms/{form}']['get']['responses'];

    $producers = array_map(static fn (array $r): string => $r['producer'], $responses['500']['x-docuccino']['provenance'] ?? []);
    $response = resolveResponse($document, $responses['500']);

    expect($producers)->toContain('fallback')
        ->and($response['description'] ?? null)->toBe('Internal Server Error')
        ->and($response)->not->toHaveKey('content');
});

it('keeps the fallback body when that same renderer delegates to the framework', function (): void {
    // The gate open on the same route set, so the only thing that moved is what the renderer returns.
    $document = renderedProbeDocument(new ReturnSite(new NullT, new SourceLocation('')));
    $responses = $document['paths']['/api/forms/{form}']['get']['responses'];
    $schema = resolveSchema($document, resolveResponse($document, $responses['500'])['content']['application/json']['schema'] ?? []);

    expect($schema['properties'] ?? null)->toBe(['message' => ['type' => 'string']]);
});

it('gives the framework tier its stock 404 back when the renderer only delegates', function (): void {
    $symbol = registerRenderCallback(
        static fn (ModelNotFoundException $e) => null,
        ModelNotFoundException::class,
    );
    app()->instance(TypeEngine::class, WorkbenchEngine::make([
        $symbol => new ActionAnalysis(returns: [new ReturnSite(new VoidT, new SourceLocation(''))]),
    ]));

    $document = generateDocument()->document->toArray();
    $response = $document['paths']['/api/forms/{form}']['get']['responses']['404'] ?? [];
    $schema = resolveSchema($document, resolveResponse($document, $response)['content']['application/json']['schema'] ?? []);

    expect($schema['properties'] ?? null)->toBe(['message' => ['type' => 'string']]);
});

/** A guard the gate cannot be widened past: `ResponseDraft` still refuses content on a bodyless status. */
it('is about the body alone — the status a tier classifies never moves', function (): void {
    $context = gateContext();
    AppRenderedErrors::record($context, ModelNotFoundException::class, 'App\\Exceptions\\Handler::render');

    $gated = (new FrameworkErrorsExceptionToResponse)->toResponse(gateThrow(ModelNotFoundException::class), $context, new ComponentRegistry);
    $open = (new FrameworkErrorsExceptionToResponse)->toResponse(gateThrow(ModelNotFoundException::class), gateContext(), new ComponentRegistry);

    expect($gated)->toBeInstanceOf(ResponseDraft::class)
        ->and($gated?->status)->toBe($open?->status)
        ->and($gated?->freeze()->description)->toBe($open?->freeze()->description);
});

it('records nothing where the tier ANSWERED, since no tier behind is asked about it', function (): void {
    // The gate exists to stand the tiers behind down, and they are only reached where this tier declines
    // — `RouteContext::mapThrow()` stops at the first answer. A renderer whose media type folded and whose
    // body did not is answered for HERE, so a note about it could be read by nothing; recording one would
    // be state written for a reader that no longer exists. The paired row below is the same renderer with
    // no media type to keep, which still declines and still owes the note.
    $symbol = registerRenderCallback(
        static fn (ProbeRejection $e) => response()->json(['dynamic' => true], 400, ['Content-Type' => 'application/problem+json']),
        ProbeRejection::class,
    );

    $answered = gateContext('default', WorkbenchEngine::make([$symbol => new ActionAnalysis(returns: [new ReturnSite(
        new ClassT('Illuminate\\Http\\JsonResponse', [new UnknownT('payload not folded'), new UnknownT('status not folded'), new LiteralT('application/problem+json')]),
        new SourceLocation(''),
    )])]));
    $declined = gateContext('default', WorkbenchEngine::make([$symbol => new ActionAnalysis(returns: [new ReturnSite(
        new ClassT('Illuminate\\Http\\JsonResponse', [new UnknownT('payload not folded'), new UnknownT('status not folded')]),
        new SourceLocation(''),
    )])]));

    $tier = app(InferredHandlerExceptionToResponse::class);

    expect($tier->toResponse(gateThrow(ProbeRejection::class), $answered, new ComponentRegistry))->not->toBeNull()
        ->and(AppRenderedErrors::includes($answered, ProbeRejection::class))->toBeFalse()
        ->and($tier->toResponse(gateThrow(ProbeRejection::class), $declined, new ComponentRegistry))->toBeNull()
        ->and(AppRenderedErrors::includes($declined, ProbeRejection::class))->toBeTrue();
});
