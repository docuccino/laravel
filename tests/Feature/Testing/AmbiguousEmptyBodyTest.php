<?php

declare(strict_types=1);

use Docuccino\Laravel\Testing\ApiContract;
use Illuminate\Http\Request;
use PHPUnit\Framework\AssertionFailedError;

/*
 * `postJson($uri, [])` — the only way a Laravel test can say "no fields" — end to end.
 *
 * PHP has one array where JSON has two containers, and the JSON test helpers take `array $data`, so
 * `json_encode` picks the list and there is no argument the author could have passed that would have
 * written `{}`. The adapter is the honest owner of that: the ambiguity is the language's, not the
 * document's, so the adapter states it and the contract decides what it permits.
 */

afterEach(function (): void {
    @unlink(sys_get_temp_dir().'/docuccino-contract-'.getmypid().'.uir.json');
    ApiContract::reset();
});

/**
 * The artifact with `POST /api/articles` documenting `$schema` as its `application/json` body.
 *
 * @param  array<string, mixed>  $schema
 */
function articleBodyOf(array $schema): void
{
    $path = workbenchContract();
    $document = json_decode((string) file_get_contents($path), true);

    $document['paths']['/api/articles']['post']['requestBody']['content']['application/json']['schema'] = $schema;
    file_put_contents($path, (string) json_encode($document));

    ApiContract::using($path);
}

/**
 * The artifact with `form.submitted` documenting `$schema` as its delivered body.
 *
 * @param  array<string, mixed>  $schema
 */
function submittedBodyOf(array $schema): void
{
    $path = workbenchWebhookContract();
    $document = json_decode((string) file_get_contents($path), true);

    $document['webhooks']['form.submitted']['post']['requestBody']['content']['application/json']['schema'] = $schema;
    file_put_contents($path, (string) json_encode($document));

    ApiContract::using($path);
}

it('puts an empty JSON ARRAY on the wire, which is the whole premise', function (): void {
    workbenchContract();

    $request = $this->postJson('api/articles', [])->baseRequest;

    // Pinned rather than assumed: if this ever stops being what the helper sends, the reading below
    // stops being owed and this is the test that says so first.
    expect($request)->toBeInstanceOf(Request::class)
        ->and($request->getContent())->toBe('[]')
        ->and($request->isJson())->toBeTrue();
});

it('passes an empty body an endpoint documents an object it accepts empty for', function (): void {
    // The reported shape: every property optional, so `{}` is a request the contract documents — and
    // `[]` is the only thing the test could have sent for it.
    articleBodyOf(['type' => 'object', 'properties' => ['heading' => ['type' => 'string']]]);

    expect(fn () => ApiContract::assertions()->assertValidRequest($this->postJson('api/articles', [])))
        ->not->toThrow(AssertionFailedError::class);
});

it('still fails an empty body where the contract accepts no empty object', function (): void {
    // The widening is offered to the schema and refused by it, so nothing is rescued: the shipped
    // shape requires `heading`, and a request that sent no fields does not satisfy it.
    articleBodyOf([
        'type' => 'object',
        'properties' => ['heading' => ['type' => 'string']],
        'required' => ['heading'],
    ]);

    expect(fn () => ApiContract::assertions()->assertValidRequest($this->postJson('api/articles', [])))
        ->toThrow(AssertionFailedError::class, 'POST /api/articles does not match the documented contract.');
});

it('still fails a populated array against an object body', function (): void {
    articleBodyOf(['type' => 'object', 'properties' => ['heading' => ['type' => 'string']]]);

    expect(fn () => ApiContract::assertions()->assertValidRequest($this->postJson('api/articles', [['heading' => 'a']])))
        ->toThrow(AssertionFailedError::class, 'POST /api/articles does not match the documented contract.');
});

it('leaves an endpoint whose body IS a list reading its empty one as a list', function (): void {
    articleBodyOf(['type' => 'array', 'items' => ['type' => 'string']]);

    expect(fn () => ApiContract::assertions()->assertValidRequest($this->postJson('api/articles', [])))
        ->not->toThrow(AssertionFailedError::class);

    // …and still holding it to what the list itself documents.
    articleBodyOf(['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']]);

    try {
        ApiContract::assertions()->assertValidRequest($this->postJson('api/articles', []));
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())->toContain('Array should have at least 1 items, 0 found');

        return;
    }

    throw new RuntimeException('an empty list where the contract wants entries should have failed');
});

it('says nothing about it on the warning channel', function (): void {
    // A note here would fire on every empty-body test in a suite, where the author has nothing to fix
    // and no other way to write the request — which is how a channel stops being read.
    articleBodyOf(['type' => 'object', 'properties' => ['heading' => ['type' => 'string']]]);

    expect(warningsRaisedBy(function (): void {
        ApiContract::assertions()->assertValidRequest($this->postJson('api/articles', []));
    }))->toBe([]);
});

/*
 * The outbound half is the same class: a delivery is handed over as whatever the application holds at
 * the moment it sends, and `json_encode` has the same choice to make about a PHP array. The one payload
 * that is NOT ambiguous is JSON text, which said which container it is in its own bytes.
 */
it('reads an ambiguous empty delivery as the empty object, and JSON text as what it says', function (): void {
    submittedBodyOf(['type' => 'object', 'properties' => ['formId' => ['type' => 'integer']]]);

    expect(fn () => ApiContract::assertions()->assertValidWebhook('form.submitted', []))
        ->not->toThrow(AssertionFailedError::class);

    expect(fn () => ApiContract::assertions()->assertValidWebhook('form.submitted', '[]'))
        ->toThrow(AssertionFailedError::class, 'must match the type: object');
});
