<?php

declare(strict_types=1);

use Docuccino\Laravel\Testing\ApiContract;
use Docuccino\Laravel\Testing\CaptureRequestBody;
use Docuccino\Laravel\Testing\FormBody;
use Docuccino\Laravel\Tests\Fixtures\Middleware\MergesATenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpFoundation\Response;

/*
 * The form half of contract testing, against a document the real producer really wrote.
 *
 * `POST /api/tickets` binds a FormRequest whose `avatar` rule is `image`, so the validation integration
 * publishes its body as a required `multipart/form-data` object — the population every upload endpoint
 * in a real application lands in. Two ways it went wrong: the whole population was unassertable, because
 * a parsed form body reached the check as nothing at all; and reading the bags LATE checked what the
 * application had made of the request rather than what the request was.
 */

afterEach(function (): void {
    @unlink(workbenchContractPath());
    ApiContract::reset();
});

/** @return array<string, mixed> */
function ticketParts(array $overrides = []): array
{
    return [...['name' => 'Widget', 'quantity' => 2, 'role' => 'admin'], ...$overrides];
}

it('publishes the multipart body these tests stand on', function (): void {
    // The premise, pinned: without it every assertion below could go on passing over a document that
    // had stopped documenting a form body at all, and prove nothing about forms.
    $document = json_decode((string) file_get_contents(workbenchContract()), true);
    $body = $document['paths']['/api/tickets']['post']['requestBody'];

    expect(array_keys($body['content']))->toBe(['multipart/form-data'])
        ->and($body['required'])->toBeTrue()
        ->and($document['components']['schemas']['StoreWidgetRequest']['required'])->toBe(['name', 'quantity', 'role'])
        ->and($document['components']['schemas']['StoreWidgetRequest']['properties']['avatar']['format'])->toBe('binary');
});

it('passes an upload that matches the multipart body the document publishes', function (): void {
    workbenchContract();

    $response = $this->post('api/tickets', ticketParts(['avatar' => UploadedFile::fake()->image('a.jpg')]));

    expect(warningsRaisedBy(function () use ($response): void {
        expect(ApiContract::assertions()->assertValidRequest($response))->toBe($response);
    }))->toBe([]);
});

it('reads a form member as the type the contract documents, the way a query value is read', function (): void {
    workbenchContract();

    // Every form member is a string on the wire, so `quantity=2` has to check as the integer 2 or every
    // typed form field in every document would fail the request that satisfied it.
    $response = $this->post('api/tickets', ticketParts(['quantity' => '2', 'avatar' => UploadedFile::fake()->image('a.jpg')]));

    expect(ApiContract::assertions()->assertValidRequest($response))->toBe($response);
});

it('holds an upload to the parts the document documents as required', function (): void {
    workbenchContract();

    try {
        ApiContract::assertions()->assertValidRequest(
            $this->post('api/tickets', ['name' => 'Widget', 'avatar' => UploadedFile::fake()->image('a.jpg')]),
        );
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('the request body')
            ->toContain('The required properties (quantity, role) are missing')
            ->toContain('schema   /components/schemas/StoreWidgetRequest');

        return;
    }

    throw new RuntimeException('the assertion should have failed');
});

it('holds a form member to the closed set the document publishes for it', function (): void {
    workbenchContract();

    try {
        ApiContract::assertions()->assertValidRequest(
            $this->post('api/tickets', ticketParts(['role' => 'nobody', 'avatar' => UploadedFile::fake()->image('a.jpg')])),
        );
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('the request body at /role')
            ->toContain('The data should match one item from enum');

        return;
    }

    throw new RuntimeException('the assertion should have failed');
});

/*
 * The two ways a suite can fail to send the multipart request its document asks for. Neither can be
 * fixed by changing the body, so both have to name the TYPE — which is the whole reason the two checks
 * report together rather than the body winning.
 */
it('names the content type when the request could not have carried the documented body', function (callable $send, string $type): void {
    workbenchContract();

    try {
        ApiContract::assertions()->assertValidRequest($send($this));
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())->toContain(sprintf(
            'sent %s, which the contract does not document as a request body (it documents multipart/form-data)',
            $type,
        ));

        return;
    }

    throw new RuntimeException('the assertion should have failed');
})->with([
    // No files, so nothing makes it a multipart message — this really is the urlencoded request the
    // contract does not document.
    'a form with no file parts' => [fn ($test) => $test->post('api/tickets', ticketParts()), 'application/x-www-form-urlencoded'],
    // `postJson()` lifts an UploadedFile out of the payload and sends the rest as JSON, so this really
    // is a JSON request. Saying so is the answer: the fix is `post()`, and dressing it up as multipart
    // would hide that the bytes on the wire were never a multipart message.
    'json with the file lifted out of it' => [
        fn ($test) => $test->postJson('api/tickets', ticketParts(['avatar' => UploadedFile::fake()->image('a.jpg')])),
        'application/json',
    ],
]);

it('tells a request that sent neither the body nor the type about both', function (): void {
    workbenchContract();

    try {
        ApiContract::assertions()->assertValidRequest($this->post('api/tickets'));
    } catch (AssertionFailedError $failure) {
        // One finding used to win, so a suite fixed the body and came straight back on the type.
        expect($failure->getMessage())
            ->toContain('sent no request body, which the contract documents as required')
            ->toContain('sent application/x-www-form-urlencoded, which the contract does not document as a request body');

        return;
    }

    throw new RuntimeException('the assertion should have failed');
});

/*
 * The guard, executed rather than asserted. A form body documented as OPTIONAL was the quietest version
 * of this defect: the check saw no bytes, the contract said a body was not required, and a request that
 * sent nothing the schema would have accepted passed having looked at nothing and said nothing.
 */
it('never lets an optional form body pass having checked nothing', function (): void {
    $path = workbenchContract();
    $document = json_decode((string) file_get_contents($path), true);
    $document['paths']['/api/tickets']['post']['requestBody']['required'] = false;
    file_put_contents($path, (string) json_encode($document));
    ApiContract::using($path);

    expect(fn () => ApiContract::assertions()->assertValidRequest(
        $this->post('api/tickets', ['name' => 'Widget', 'avatar' => UploadedFile::fake()->image('a.jpg')]),
    ))->toThrow(AssertionFailedError::class, 'The required properties (quantity, role) are missing');
});

it('reduces a parsed form request to the fields and the type a real client would have sent', function (): void {
    workbenchContract();

    $response = $this->post('api/tickets', ticketParts(['avatar' => UploadedFile::fake()->image('badge.png')]));
    $exchange = ApiContract::exchangeFor($response->baseRequest, $response);

    // The premise, pinned twice over: the framework really has drained the body, and the test client
    // really did label a file upload as urlencoded. Without both, this test proves nothing.
    expect($response->baseRequest->getContent())->toBe('')
        ->and($response->baseRequest->headers->get('Content-Type'))->toBe('application/x-www-form-urlencoded')
        ->and($exchange->requestContentType)->toBe('multipart/form-data')
        ->and($exchange->requestForm)->toBe(['name' => 'Widget', 'quantity' => 2, 'role' => 'admin', 'avatar' => 'badge.png']);
});

it('reads a request with no file parts as the urlencoded message it is', function (): void {
    workbenchContract();

    $response = $this->post('api/tickets', ticketParts());
    $exchange = ApiContract::exchangeFor($response->baseRequest, $response);

    expect($exchange->requestContentType)->toBe('application/x-www-form-urlencoded')
        ->and($exchange->requestForm)->toBe(['name' => 'Widget', 'quantity' => 2, 'role' => 'admin']);
});

it('leaves a request whose bytes are still there alone', function (string $body, string $type): void {
    workbenchContract();

    $response = contractResponse('POST', '/api/tickets', status: 201, requestBody: $body, requestHeaders: ['Content-Type' => $type]);
    $exchange = ApiContract::exchangeFor($response->baseRequest, $response);

    expect($exchange->requestForm)->toBeNull()
        ->and($exchange->requestBody)->toBe($body)
        ->and($exchange->requestContentType)->toBe($type);
})->with([
    'json' => ['{"name":"Widget"}', 'application/json'],
    // Bytes nothing parsed are the body even when they are a form: reading a bag instead would answer
    // about a decode of the message rather than about the message.
    'urlencoded bytes' => ['name=Widget', 'application/x-www-form-urlencoded'],
]);

it('reports no form body for a request that carried none', function (): void {
    workbenchContract();

    expect(FormBody::read($this->get('api/forms')->baseRequest))->toBe([null, null]);
});

it('reports a multipart request that declared itself one, files or no files', function (): void {
    workbenchContract();

    $response = $this->post('api/tickets', ticketParts(), ['Content-Type' => 'multipart/form-data; boundary=xyz']);

    expect(FormBody::read($response->baseRequest)[0]?->contentType)->toBe('multipart/form-data');
});

/*
 * The message, and not the application's rewrite of it.
 *
 * `$request->request` is not the wire: `TrimStrings` and `ConvertEmptyStringsToNull` are in Laravel's
 * DEFAULT global stack and rewrite the bag in place, and any `$request->merge()` puts fields in it that
 * no client sent. A check that read the bag held the document to what the application made of the
 * request — passing a body that was over a `maxLength` on the wire, failing one that was fine, and
 * reporting a request that sent nothing at all as having sent a urlencoded body.
 */
it('holds the contract to the value the client sent, not the one the transforms left behind', function (mixed $sent, ?string $failure): void {
    workbenchContract();

    $response = $this->post('api/tickets', ticketParts(['name' => $sent, 'avatar' => UploadedFile::fake()->image('a.jpg')]));

    // The premise, pinned: the framework really did rewrite the bag out from under the check.
    expect($response->baseRequest->request->get('name'))->not->toBe($sent);

    $assert = fn () => ApiContract::assertions()->assertValidRequest($response);

    $failure === null
        ? expect($assert())->toBe($response)
        : expect($assert)->toThrow(AssertionFailedError::class, $failure);
})->with([
    // `ConvertEmptyStringsToNull` turns this into `null`, which fails the documented `type: string`.
    'an empty string the contract documents as a string' => ['', null],
    // 101 characters on the wire, 99 after `TrimStrings` — the whole point of `maxLength: 100`.
    'a value over maxLength that trims under it' => [str_repeat('a', 99).'  ', 'Maximum string length is 100'],
]);

it('never reads a field the application merged as a field the client sent', function (): void {
    $path = workbenchContract();
    $document = json_decode((string) file_get_contents($path), true);
    // The document that makes a merged field visible: an extra property is a violation under it.
    $document['components']['schemas']['StoreWidgetRequest']['additionalProperties'] = false;
    file_put_contents($path, (string) json_encode($document));
    ApiContract::using($path);

    $this->app->make(Kernel::class)->pushMiddleware(MergesATenant::class);

    $response = $this->post('api/tickets', ticketParts(['avatar' => UploadedFile::fake()->image('a.jpg')]));

    // The premise, pinned: the merge really happened, and after the capture.
    expect($response->baseRequest->request->get('tenant'))->toBe('acme')
        ->and(ApiContract::exchangeFor($response->baseRequest, $response)->requestForm)
        ->not->toHaveKey('tenant');

    expect(ApiContract::assertions()->assertValidRequest($response))->toBe($response);
});

/*
 * The guard against handing the same lie back in a new costume: where nothing captured the message, the
 * check says so rather than reading the bag it has.
 */
it('reads a bag nothing captured as evidence of nothing', function (): void {
    workbenchContract();

    $request = Request::create('/api/tickets', 'POST');
    $request->merge(['tenant' => 'acme']);

    [$form, $unread] = FormBody::read($request);

    expect($form)->toBeNull()
        ->and($unread)->toContain('nothing captured the form body');
});

it('says a request nothing captured was not checked, rather than failing it for sending no body', function (): void {
    workbenchContract();

    $request = Request::create('/api/tickets', 'POST');
    $request->merge(['tenant' => 'acme']);
    $response = TestResponse::fromBaseResponse(
        new Response('', 201, ['Content-Type' => 'application/json']),
        $request,
    );

    expect(warningsRaisedBy(function () use ($response): void {
        ApiContract::assertions()->assertValidRequest($response);
    }))->toBe(['POST /api/tickets passed, but part of the contract was not checked: the request body could not '.
        'be read: nothing captured the form body before the application could rewrite it — take the '.
        'Docuccino\Laravel\Testing\AssertsApiContract trait on your test case, or call '.
        'ApiContract::captureRequestBodies() once the application is up.']);
});

it('captures for a test case that takes the trait, ahead of every transform in the stack', function (): void {
    $middleware = $this->app->make(Kernel::class)->getGlobalMiddleware();

    expect($middleware[0])->toBe(CaptureRequestBody::class)
        ->and($middleware)->toContain(TrimStrings::class);
});

it('keeps a mixed file and text part in the positions the message sent them', function (): void {
    workbenchContract();

    $response = $this->post('api/tickets', ticketParts([
        'avatar' => UploadedFile::fake()->image('a.jpg'),
        'docs' => [UploadedFile::fake()->image('f.jpg'), 'caption'],
    ]));

    $form = ApiContract::exchangeFor($response->baseRequest, $response)->requestForm;

    // A list, in wire order — `array_replace_recursive` appended the file's index instead of matching
    // it, so `docs` came back as `{"1":"caption","0":"f.jpg"}` and a documented `type: array` had its
    // positions swapped under it.
    expect($form['docs'])->toBe(['f.jpg', 'caption'])
        ->and(json_encode($form['docs']))->toBe('["f.jpg","caption"]');
});

it('says it could not check a name the message sent as both a field and a file part', function (): void {
    workbenchContract();

    $response = $this->call('POST', 'api/tickets', ticketParts(['avatar' => 'a name']), [], [
        'avatar' => UploadedFile::fake()->image('a.jpg'),
    ]);

    expect(warningsRaisedBy(function () use ($response): void {
        ApiContract::assertions()->assertValidRequest($response);
    }))->toBe(['POST /api/tickets passed, but part of the contract was not checked: the request body could not '.
        'be read: the request sent "avatar" as both a field and a file part, which one schema cannot describe.']);
});

it('checks a form whose field names are numbers against the object schema documenting it', function (): void {
    workbenchContract();

    // PHP re-canonicalises a numeric string key to an int, so these fields are a LIST to `json_encode`
    // and the body checked as a JSON array against an object schema — a documented object failing for
    // the wrong reason, with its properties never looked at.
    $response = $this->post('api/tickets', ['0' => 'a', '1' => 'b'], ['Content-Type' => 'multipart/form-data; boundary=xyz']);

    expect(fn () => ApiContract::assertions()->assertValidRequest($response))
        ->toThrow(AssertionFailedError::class, 'The required properties (name, quantity, role) are missing');
});
