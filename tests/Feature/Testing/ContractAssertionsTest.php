<?php

declare(strict_types=1);

use Docuccino\Laravel\Testing\ApiContract;
use Docuccino\Laravel\Testing\Contracts\ContractObserver;
use Docuccino\Laravel\Testing\ObservedExchange;
use Docuccino\Laravel\Testing\UnreadableContract;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;

afterEach(function (): void {
    @unlink(sys_get_temp_dir().'/docuccino-contract-'.getmypid().'.uir.json');
    ApiContract::reset();
});

it('passes a response that matches the document the generator produced', function (): void {
    workbenchContract();

    $response = contractResponse('GET', '/api/forms', body: '[{"id":1,"title":"Intake"}]');

    expect(ApiContract::assertions()->assertValidResponse($response))->toBe($response);
});

it('fails a response whose shape disagrees, naming the producer that wrote the schema', function (): void {
    workbenchContract();

    expect(fn () => ApiContract::assertions()->assertValidResponse(
        contractResponse('GET', '/api/forms', body: '{"data":[]}'),
    ))->toThrow(
        AssertionFailedError::class,
        'GET /api/forms does not match the documented contract.',
    );

    try {
        ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '{"data":[]}'));
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('operation  GET /api/forms  op:v1:')
            ->toContain('must match the type: array')
            ->toContain('schema   /paths/~1api~1forms/get/responses/200/content/application~1json/schema')
            ->toContain('from     inference (inference)');
    }
});

it('names the file and line a failing path parameter was inferred from', function (): void {
    workbenchContract();

    try {
        ApiContract::assertions()->assertValidRequest(
            contractResponse('GET', '/api/archived-forms/not-a-number', body: '{"id":1,"title":"x"}'),
        );
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('path {form}')
            ->toContain('must match the type: integer')
            ->toContain('from     inference (inference) — workbench/app/Http/Controllers/FormController.php:32');

        return;
    }

    throw new RuntimeException('the assertion should have failed');
});

it('fails an exchange the document describes no operation for', function (): void {
    workbenchContract();

    try {
        ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/nowhere', body: '{}'));
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('GET /api/nowhere is not documented.')
            ->toContain('The contract documents these GET paths:')
            ->toContain('php artisan docuccino:export');

        return;
    }

    throw new RuntimeException('the assertion should have failed');
});

/*
 * A pass that could not read what the document published says so, on the channel a developer running the
 * suite actually sees. A note nobody is told is how a suite comes to believe it has contract coverage it
 * does not have — and the docs promise these notes exist.
 *
 * The artifact here is a real build with one media type edited, deliberately: Docuccino's own producer
 * publishes a JSON schema for every response it writes, so the population an inbound note fires against
 * is a hand-written or imported document, and that is what this stands in for.
 */
it('warns the developer that an exchange passed having proved less than it looks like', function (): void {
    $path = workbenchContract();
    $document = json_decode((string) file_get_contents($path), true);

    $content = $document['paths']['/api/forms']['get']['responses']['200']['content'];
    expect($content)->toHaveKey('application/json');

    $document['paths']['/api/forms']['get']['responses']['200']['content'] = ['text/csv' => $content['application/json']];
    file_put_contents($path, (string) json_encode($document));
    ApiContract::using($path);

    $warnings = warningsRaisedBy(static function (): void {
        ApiContract::assertions()->assertValidResponse(
            contractResponse('GET', '/api/forms', body: "id,title\n1,Intake\n", headers: ['Content-Type' => 'text/csv']),
        );
    });

    expect($warnings)->toBe([
        'GET /api/forms passed, but part of the contract was not checked: '.
        'the response body is text/csv, which JSON Schema cannot check.',
    ]);
});

it('says nothing at all about an exchange it checked in full', function (): void {
    workbenchContract();

    expect(warningsRaisedBy(static function (): void {
        ApiContract::assertions()->assertValidExchange(contractResponse('GET', '/api/forms', body: '[{"id":1,"title":"Intake"}]'));
    }))->toBe([]);
});

it('checks the request, the response, or both, as asked', function (): void {
    workbenchContract();

    // The body is wrong for the response but the request is fine, so only the response half objects.
    $response = contractResponse('GET', '/api/forms', body: '{"data":[]}');

    expect(fn () => ApiContract::assertions()->assertValidRequest($response))->not->toThrow(AssertionFailedError::class);
    expect(fn () => ApiContract::assertions()->assertValidResponse($response))->toThrow(AssertionFailedError::class);
    expect(fn () => ApiContract::assertions()->assertValidExchange($response))->toThrow(AssertionFailedError::class);
});

it('asserts through a real routed response', function (): void {
    workbenchContract();

    // The workbench action returns `{"data": []}` where the document promises an array of FormData —
    // a genuine disagreement between the code and its own documentation, caught end to end.
    expect(fn () => ApiContract::assertions()->assertValidResponse($this->getJson('api/forms')))
        ->toThrow(AssertionFailedError::class, 'GET /api/forms does not match the documented contract.');
});

it('registers the three assertions on TestResponse itself', function (): void {
    workbenchContract();
    ApiContract::registerMacros();

    $ok = contractResponse('GET', '/api/forms', body: '[]');

    expect($ok->assertValidResponse())->toBe($ok)
        ->and($ok->assertValidRequest())->toBe($ok)
        ->and($ok->assertValidExchange())->toBe($ok);

    expect(fn () => contractResponse('GET', '/api/forms', body: '{}')->assertValidResponse())
        ->toThrow(AssertionFailedError::class);

    TestResponse::flushMacros();
});

it('tells observers about every matched exchange, failing ones included', function (): void {
    workbenchContract();

    $seen = new class implements ContractObserver
    {
        /** @var list<string> */
        public array $exchanges = [];

        public function observed(ObservedExchange $exchange): void
        {
            $this->exchanges[] = sprintf(
                '%s %s %d %s',
                $exchange->method(),
                $exchange->pathTemplate(),
                $exchange->status(),
                $exchange->result->ok() ? 'ok' : 'failed',
            );
        }
    };

    ApiContract::observe($seen);

    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));

    try {
        ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '{}'));
    } catch (AssertionFailedError) {
        // The point is that the observer saw it anyway.
    }

    expect($seen->exchanges)->toBe([
        'GET /api/forms 200 ok',
        'GET /api/forms 200 failed',
    ]);
});

it('hands an observer the request, the response and the operation a recorder would need', function (): void {
    workbenchContract();

    $captured = null;
    ApiContract::observe(new class($captured) implements ContractObserver
    {
        public function __construct(private mixed &$captured) {}

        public function observed(ObservedExchange $exchange): void
        {
            $this->captured = $exchange;
        }
    });

    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));

    expect($captured)->toBeInstanceOf(ObservedExchange::class)
        ->and($captured->operationId())->toStartWith('op:v1:')
        ->and($captured->request->getPathInfo())->toBe('/api/forms')
        ->and($captured->response)->toBeInstanceOf(TestResponse::class)
        ->and($captured->body())->toBe('[]')
        ->and($captured->exchange->method)->toBe('GET');
});

it('reads the artifact once however many times the suite asserts against it', function (): void {
    $path = workbenchContract();

    ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]'));
    unlink($path);

    // Still answers: the artifact was indexed on first use, not re-read per assertion.
    expect(fn () => ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/forms', body: '[]')))
        ->not->toThrow(AssertionFailedError::class);
});

it('says how to produce an artifact that is not there', function (): void {
    ApiContract::using(sys_get_temp_dir().'/docuccino-missing-'.getmypid().'.json');

    expect(fn () => ApiContract::index())
        ->toThrow(UnreadableContract::class, 'php artisan docuccino:export');
});

it('says so when the artifact is not a JSON document', function (): void {
    $path = sys_get_temp_dir().'/docuccino-contract-'.getmypid().'.uir.json';
    file_put_contents($path, 'not json at all');
    ApiContract::using($path);

    expect(fn () => ApiContract::index())->toThrow(UnreadableContract::class, 'is not a JSON document');
});

it('refuses a document key nothing configures', function (): void {
    ApiContract::forDocument('nope');

    expect(fn () => ApiContract::build())->toThrow(UnreadableContract::class, 'No document "nope" is configured');
});

it('reads the document’s own uir export target when the suite names no path', function (): void {
    config()->set('docuccino.documents.default.export.targets', [
        ['format' => 'openapi-3.2', 'path' => 'docs/openapi.json'],
        ['format' => 'uir', 'path' => 'docs/api.uir.json'],
    ]);

    expect(ApiContract::artifactPath())->toEndWith('/docs/api.uir.json');
});

it('falls back to whatever the document does write when it writes no uir', function (): void {
    config()->set('docuccino.documents.default.export.targets', [
        ['format' => 'openapi-3.1', 'path' => 'docs/openapi-3.1.json'],
    ]);

    expect(ApiContract::artifactPath())->toEndWith('/docs/openapi-3.1.json');
});

/*
 * The rate-limit integration documents a `429` with `Retry-After` and the `X-RateLimit-*` trio. Those
 * header claims used to be published and never held to anything; these three pin the whole path — the
 * real workbench build, the real emitter, the real assertion.
 */
it('passes a 429 whose rate-limit headers say what the document says they say', function (): void {
    workbenchContract();

    $response = contractResponse('GET', '/api/rate-limited', status: 429, body: '{"message":"Too Many Attempts."}', headers: [
        'Content-Type' => 'application/json',
        'Retry-After' => '30',
        'X-RateLimit-Limit' => '60',
        'X-RateLimit-Remaining' => '0',
        'X-RateLimit-Reset' => '1735689600',
    ]);

    expect(ApiContract::assertions()->assertValidResponse($response))->toBe($response);
});

it('fails a 429 whose rate-limit header is not the type the document publishes', function (): void {
    workbenchContract();

    try {
        ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/rate-limited', status: 429, body: '{"message":"Too Many Attempts."}', headers: [
            'Content-Type' => 'application/json',
            'Retry-After' => 'in a bit',
            'X-RateLimit-Limit' => '60',
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => '1735689600',
        ]));
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('the response header Retry-After')
            ->toContain('must match the type: integer')
            ->toContain('/responses/429/headers/Retry-After/schema')
            ->toContain('from     integration:rate-limit (integration)');

        return;
    }

    throw new RuntimeException('the assertion should have failed');
});

/*
 * The integration publishes all four as `required`, because ThrottleRequests sends all four. This is the
 * only place the required-header branch is exercised against a document the product really generated —
 * every other proof of it stands on a hand-written contract, which cannot show that anything Docuccino
 * emits ever reaches it.
 */
it('fails a 429 that omits a rate-limit header the document marks required', function (): void {
    workbenchContract();

    try {
        ApiContract::assertions()->assertValidResponse(contractResponse('GET', '/api/rate-limited', status: 429, body: '{"message":"Too Many Attempts."}', headers: [
            'Content-Type' => 'application/json',
            'X-RateLimit-Limit' => '60',
        ]));
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('the response header Retry-After')
            ->toContain('is documented as required, but the response did not send it')
            ->toContain('/responses/429/headers/Retry-After')
            ->toContain('from     integration:rate-limit (integration)')
            // Every absent one is named, not just the first — three headers went missing here.
            ->toContain('the response header X-RateLimit-Remaining')
            ->toContain('the response header X-RateLimit-Reset');

        return;
    }

    throw new RuntimeException('the assertion should have failed');
});

it('reduces the response headers Laravel sent, repeats and all, to the neutral exchange', function (): void {
    $response = contractResponse('GET', '/api/forms', headers: [
        'Content-Type' => 'application/json',
        'X-Chunk' => ['1', '2'],
    ]);

    $exchange = ApiContract::exchangeFor($response->baseRequest, $response);

    expect($exchange->responseHeader('x-chunk'))->toBe(['1', '2'])
        ->and($exchange->responseHeader('Content-Type'))->toBe(['application/json'])
        ->and($exchange->responseHeader('X-Nothing'))->toBe([]);
});

it('reduces the request headers Laravel sent, repeats and all, to the neutral exchange', function (): void {
    // Both halves read the same `HeaderBag`, which models a list on both sides — so keeping the first
    // value of a request header threw away something the framework had. It is reachable: `$_SERVER`
    // collapses a repeat into one comma list, but appending to the bag does not, and middleware
    // appends to the very request object the assertions read back.
    $response = contractResponse('GET', '/api/forms');
    $request = $response->baseRequest;
    $request->headers->set('X-Trace', 'first');
    $request->headers->set('X-Trace', 'second', false);

    $exchange = ApiContract::exchangeFor($request, $response);

    // The premise, pinned: without it this would pass over a bag that never held two values.
    expect($request->headers->all('X-Trace'))->toBe(['first', 'second'])
        ->and($exchange->header('x-trace'))->toBe(['first', 'second'])
        ->and($exchange->header('X-Nothing'))->toBe([]);
});
