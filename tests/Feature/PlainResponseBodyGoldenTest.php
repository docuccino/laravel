<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\NoticeController;

/**
 * The document an application gets for plain responses handed a string, nothing, an array or content of no
 * one kind, in every spelling that makes one: `new Response()`, the `response()` helper, `response()->make()`,
 * the `Response` facade, Symfony's own response and a subclass handing its parent the content, with and
 * without a `Content-Type` header. Byte-locked, warm as well as cold; the scripted returns are what the real engine
 * recovers from the fixture app's same calls, which the last test holds them to.
 *
 * @return array<string, DType> action → the response it returns
 */
function plainResponseBodyReturns(): array
{
    $json = static fn (DType $body, int $status, DType ...$mediaType): ClassT => new ClassT('Illuminate\\Http\\JsonResponse', [$body, new LiteralT($status), ...$mediaType]);
    $text = new LiteralT('The export has expired.');
    $html = new LiteralT('text/html');
    $locked = new ArrayShapeT([new ArrayShapeField('error', new LiteralT('locked'))]);
    [$unread, $any] = [new UnknownT('payload not folded'), new LiteralT('*/*')];

    return [
        // A string is sent as it is, under the label Symfony gives a body nobody labelled: the text itself.
        'constructed' => $json($text, 410, $html),
        'helper' => $json($text, 410, $html),
        'facade' => $json($text, 410, $html),
        'factory' => $json($text, 410, $html),
        'symfony' => $json($text, 410, $html),
        'subclass' => $json($text, 410, $html),
        // A string nothing folds is still text, of no one value.
        'helperMessage' => $json(ScalarT::string(), 503, $html),
        // A `Content-Type` header relabels the text.
        'constructedCsv' => $json(new LiteralT('id,name'), 200, new LiteralT('text/csv')),
        'helperPlain' => $json($text, 410, new LiteralT('text/plain')),
        // Empty content is no body at all.
        'constructedEmpty' => $json(new VoidT, 410),
        'helperEmpty' => $json(new VoidT, 200),
        // An array is morphed to JSON and labelled `application/json`, over any header the call stated.
        'constructedArray' => $json($locked, 422),
        'constructedArrayLabelled' => $json($locked, 422),
        'helperArray' => $json($locked, 200),
        'subclassArray' => $json($locked, 423),
        // A view is rendered, so the body is the HTML it renders.
        'helperView' => $json(new ClassT('Illuminate\\Contracts\\View\\View'), 410),
        // Content that may be JSON or text is either, under its own label, at any status: an open body under
        // any media type, the header a call states included, since JSON is relabelled over it.
        'helperCached' => $json($unread, 410, $any),
        'helperCachedOk' => $json($unread, 200, $any),
        'constructedTextOrArray' => $json($unread, 410, $any),
        'helperCachedCsv' => $json($unread, 200, $any),
        // Content of no one kind that cannot be JSON is labelled as text is: the stated label, or Symfony's.
        'helperFile' => $json($unread, 200, new LiteralT('image/png')),
        'symfonyCached' => $json($unread, 410, $html),
    ];
}

/** @return callable(): TypeEngine */
function plainResponseBodyEngine(): callable
{
    $analyses = [];
    foreach (plainResponseBodyReturns() as $action => $type) {
        $analyses[NoticeController::class.'::'.$action] = new ActionAnalysis(returns: [new ReturnSite($type, new SourceLocation(''))]);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

function plainResponseBodyRoutes(): callable
{
    return static function (Router $router): void {
        foreach (array_keys(plainResponseBodyReturns()) as $action) {
            $router->get('api/notices/'.strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $action)), [NoticeController::class, $action]);
        }
    };
}

it('publishes what a plain response sends for the content it is handed, byte-identically', function (): void {
    $warm = assertWarmEqualsCold(plainResponseBodyRoutes(), plainResponseBodyRoutes(), plainResponseBodyEngine());

    assertGolden('workbench-plain-response-body.uir.json', (new UirEmitter)->emit($warm->document));
});

it('documents each body under the media type Laravel labels it with', function (): void {
    $document = emittedArray(localityBuild(plainResponseBodyRoutes(), plainResponseBodyEngine()));

    // Each route's one response, through the shared error component where it was hoisted to one, as media
    // type → schema with provenance left out.
    $content = static function (string $path) use ($document): ?array {
        $response = array_values($document['paths']['/api/notices/'.$path]['get']['responses'])[0];
        $ref = $response['$ref'] ?? null;
        $response = is_string($ref) ? $document['components']['responses'][substr($ref, strlen('#/components/responses/'))] : $response;

        return isset($response['content']) ? array_map(
            static fn (array $media): array => array_diff_key(resolveSchema($document, $media['schema']), ['x-docuccino' => true, '$ref' => true]),
            $response['content'],
        ) : null;
    };
    $text = ['type' => 'string', 'const' => 'The export has expired.'];

    // Laravel sends a string as it is, which Symfony labels `text/html` unless a header says otherwise; a
    // literal is that text, as any literal in a body is.
    expect($content('constructed'))->toBe(['text/html' => $text])
        ->and($content('helper'))->toBe(['text/html' => $text])
        ->and($content('facade'))->toBe(['text/html' => $text])
        ->and($content('factory'))->toBe(['text/html' => $text])
        ->and($content('symfony'))->toBe(['text/html' => $text])
        ->and($content('subclass'))->toBe(['text/html' => $text])
        ->and($content('helper-message'))->toBe(['text/html' => ['type' => 'string']])
        // The header the code states is the label sent, and so the media type documented.
        ->and($content('constructed-csv'))->toBe(['text/csv' => ['type' => 'string', 'const' => 'id,name']])
        ->and($content('helper-plain'))->toBe(['text/plain' => $text])
        // Empty content is an empty body, which no schema describes.
        ->and($content('constructed-empty'))->toBeNull()
        ->and($content('helper-empty'))->toBeNull()
        // A view is rendered HTML.
        ->and(array_keys($content('helper-view') ?? []))->toBe(['text/html'])
        // Content that may be an array or text is sent as JSON under `application/json` or as text under the
        // stated label or `text/html`, so the document can name no one media type or shape for it, at a 410
        // or a 200 alike, and a stated header is no exception, since JSON is relabelled over it.
        ->and($content('helper-cached'))->toBe(['*/*' => []])
        ->and($content('helper-cached-ok'))->toBe(['*/*' => []])
        ->and($content('constructed-text-or-array'))->toBe(['*/*' => []])
        ->and($content('helper-cached-csv'))->toBe(['*/*' => []])
        // Content that cannot be JSON keeps the label it is sent with, of a shape nothing read: a file's
        // bytes or `false` under the stated label, and whatever Symfony's response is handed, which takes only
        // a string, under `text/html`.
        ->and($content('helper-file'))->toBe(['image/png' => []])
        ->and($content('symfony-cached'))->toBe(['text/html' => []]);

    // An array is morphed to JSON and relabelled `application/json`, whatever header the call stated.
    foreach (['constructed-array', 'constructed-array-labelled', 'helper-array', 'subclass-array'] as $path) {
        expect(array_keys($content($path) ?? []))->toBe(['application/json'], $path)
            ->and(array_keys($content($path)['application/json']['properties'] ?? []))->toBe(['error'], $path);
    }
});

it('scripts what the real engine recovers from the fixture app’s same calls', function (): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $returns = plainResponseBodyReturns();
    $real = FixtureRunner::analyzeMany(
        'app/Http/Controllers/PlainResponseBodyController.php',
        'App\\Http\\Controllers\\PlainResponseBodyController',
        array_keys($returns),
    );

    foreach ($returns as $action => $scripted) {
        expect(array_map(static fn (ReturnSite $site): DType => $site->type, ActionAnalysis::fromArray($real[$action])->returns))
            ->toEqual([$scripted], $action);
    }
})->group('fixture');
