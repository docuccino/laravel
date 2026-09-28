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
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\StatusChoice\StatusChoiceController;
use Docuccino\Laravel\Tests\Fixtures\StatusChoice\StatusChoiceResource;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * A status that is a choice between constants, locked in emitted bytes. The document owes every status the
 * server can send — `$ok ? 200 : 503` is two responses, as the same endpoint written as two returns is —
 * and a status nothing can read is none of them: it is `default`, until the author names the codes. The
 * analyses are the types the real engine recovers for these shapes (`StatusChoiceTest`).
 */
afterEach(fn () => removeFragmentCacheDirs('status-choice'));

beforeEach(function (): void {
    $location = new SourceLocation('');
    $json = static fn (DType ...$args): ClassT => new ClassT('Illuminate\\Http\\JsonResponse', $args);
    $codes = static fn (int ...$codes): DType => UnionT::of(array_map(static fn (int $c): LiteralT => new LiteralT($c), $codes));
    $site = static fn (DType ...$types): ActionAnalysis => new ActionAnalysis(returns: array_map(static fn (DType $t): ReturnSite => new ReturnSite($t, $location), $types));
    $ok = static fn (DType $value): ArrayShapeT => new ArrayShapeT([new ArrayShapeField('ok', $value)]);
    $action = static fn (string $method): string => StatusChoiceController::class.'::'.$method;

    $this->engine = static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: [
        $action('chained') => $site($json($ok(ScalarT::bool()), $codes(200, 503))),
        $action('branches') => $site($json($ok(new LiteralT(true)), new LiteralT(200)), $json($ok(new LiteralT(false)), new LiteralT(503))),
        $action('upsert') => $site($json(new ClassT(StatusChoiceResource::class), $codes(200, 201))),
        $action('requested') => $site($json($ok(new LiteralT(true)), new UnknownT('status not folded'))),
        $action('named') => $site($json($ok(new LiteralT(true)), new UnknownT('status not folded'))),
        $action('namedHeader') => $site($json($ok(new LiteralT(true)), new UnknownT('status not folded'))),
        $action('namedError') => $site($json($ok(new LiteralT(true)), new UnknownT('status not folded'))),
        $action('namedEmpty') => $site($json($ok(new LiteralT(true)), new UnknownT('status not folded'))),
        $action('emptied') => $site($json(new VoidT, $codes(204, 205))),
        StatusChoiceResource::class.'::toArray' => $site(new ArrayShapeT([new ArrayShapeField('id', ScalarT::int())])),
    ]);

    $this->routes = static function (Router $router): void {
        foreach (['chained', 'branches', 'requested', 'named', 'namedHeader', 'namedError', 'namedEmpty'] as $method) {
            $router->get('api/zz-status/'.$method, [StatusChoiceController::class, $method]);
        }
        $router->put('api/zz-status/upsert', [StatusChoiceController::class, 'upsert']);
        $router->delete('api/zz-status/emptied', [StatusChoiceController::class, 'emptied']);
    };
});

it('publishes every status a choice of constants can send byte-identical to its committed golden', function (): void {
    $result = localityBuild($this->routes, $this->engine);

    assertGolden('status-choice.uir.json', (new UirEmitter)->emit($result->document));
});

it('owes a response per code the server can send, each described as itself', function (): void {
    $result = localityBuild($this->routes, $this->engine);
    $document = emittedArray($result);
    $responses = static fn (string $path, string $verb = 'get'): array => $document['paths']['/api/zz-status/'.$path][$verb]['responses'];
    $describe = static fn (array $responses): array => array_map(static fn (array $r): string => $r['description'], $responses);
    $body = static fn (array $response): array => array_diff_key($response['content']['application/json']['schema'], ['x-docuccino' => true]);

    // Both codes, the same body under each: the body cannot be told apart by the status from one
    // expression, so each carries the whole of what is known — a boolean — and never a guessed `const`.
    expect($describe($responses('chained')))->toBe([200 => 'OK', 503 => 'Service Unavailable'])
        ->and($body($responses('chained')['503']))->toBe($body($responses('chained')['200']))
        ->and($body($responses('chained')['200'])['properties']['ok'])->toBe(['type' => 'boolean'])
        // The two-return form it matches, 503 described by its own phrase rather than as "OK".
        ->and($describe($responses('branches')))->toBe([200 => 'OK', 503 => 'Service Unavailable'])
        // A create-or-return: both statuses carry the resource.
        ->and($describe($responses('upsert', 'put')))->toBe([200 => 'OK', 201 => 'Created'])
        ->and($body($responses('upsert', 'put')['201']))->toBe($body($responses('upsert', 'put')['200']))
        // An empty response chosen between two empty statuses is two empty responses.
        ->and($describe($responses('emptied', 'delete')))->toBe([204 => 'No Content', 205 => 'Reset Content'])
        ->and($responses('emptied', 'delete')[205])->not->toHaveKey('content');

    // Unread: no code is invented. The body is published under `default`, "any status".
    expect(array_keys($responses('requested')))->toBe(['default'])
        ->and($responses('requested')['default']['description'])->toBe('Any status')
        ->and($body($responses('requested')['default'])['properties']['ok'])->toHaveKey('const');

    // Named: a success code the author names takes the inferred body, and the stand-in retires with it.
    // An error code named beside it adds a response and takes nothing: it may be a failure path of its own,
    // and without a `type:` it says nothing about a body.
    expect(array_keys($responses('named')))->toBe([200, 503])
        ->and($body($responses('named')['200']))->toBe($body($responses('requested')['default']))
        ->and($responses('named')[503])->not->toHaveKey('content')
        ->and($responses('named')[503]['description'])->toBe('Service Unavailable');

    // A header declared at a success status names that status too; an empty one takes all but the body.
    expect(array_keys($responses('namedHeader')))->toBe([202])
        ->and($body($responses('namedHeader')[202]))->toBe($body($responses('requested')['default']))
        ->and(array_keys($responses('namedEmpty')))->toBe([204])
        ->and($responses('namedEmpty')[204])->not->toHaveKey('content')
        // An error code alone leaves the stand-in standing beside it.
        ->and(array_keys($responses('namedError')))->toBe([503, 'default']);
});

it('tells the author about an unread status exactly where `default` survives', function (): void {
    // The notice's whole claim is that the body sits under `default`; a declaration that retired it has
    // done what the notice would ask, so the two are read off the finished document side by side.
    $result = localityBuild($this->routes, $this->engine);
    $document = emittedArray($result);

    $noticed = [];
    foreach ($result->diagnostics as $diagnostic) {
        if ($diagnostic->code === 'inferred-response.status-unread') {
            $noticed[] = $diagnostic->routeSignature;
        }
    }

    $survives = [];
    foreach ($document['paths'] as $path => $operations) {
        foreach ($operations as $verb => $operation) {
            if (is_array($operation) && array_key_exists('default', $operation['responses'] ?? [])) {
                $survives[] = strtoupper($verb).' '.$path;
            }
        }
    }
    sort($noticed);
    sort($survives);

    expect($survives)->toBe(['GET /api/zz-status/namedError', 'GET /api/zz-status/requested'])
        ->and($noticed)->toBe($survives);
});

it('is the same document warm as cold', function (): void {
    fragmentCacheDir('status-choice');

    $cold = localityBuild($this->routes, $this->engine);
    $warm = localityBuild($this->routes, $this->engine, $counting);

    expect($counting->analyzeCount)->toBe(0)
        ->and((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document))
        ->and(diagnosticRecords($warm->diagnostics))->toBe(diagnosticRecords($cold->diagnostics));
});
