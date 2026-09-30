<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\MeteredJsonApiResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\PaginatedJsonApiController;
use Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi\LinkedTimacdonaldCollection;
use Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi\TimacdonaldArticleResource;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Router;
use Opis\JsonSchema\Validator;
use TiMacDonald\JsonApi\JsonApiResourceCollection;

/**
 * A page of JSON:API resources, through the pipeline and against the body each family really sends: both
 * put Laravel's page envelope beside `data`, and timacdonald's `paginationInformation()` drops the links
 * there is no page for — so its links are published as that, and Laravel's shared parts stay Laravel's.
 */
beforeEach(function (): void {
    $location = new SourceLocation('');
    $shape = static fn (array $fields): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT($fields), $location)]);
    $returns = static fn (ClassT $type): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite($type, $location)]);
    $this->chains = ['length' => '$q->paginate(15)', 'simple' => '$q->simplePaginate(15)', 'cursor' => '$q->cursorPaginate(15)'];

    $analyses = [
        MeteredJsonApiResource::class.'::toAttributes' => $shape([new ArrayShapeField('title', ScalarT::string())]),
        TimacdonaldArticleResource::class.'::toAttributes' => $shape([new ArrayShapeField('title', ScalarT::string())]),
    ];

    $this->engineFor = static function (string $chain) use ($analyses, $returns): callable {
        return static fn (): TypeEngine => WorkbenchEngine::make(
            analysisOverrides: [
                ...$analyses,
                PaginatedJsonApiController::class.'::firstParty' => $returns(new ClassT(ResourceReflector::JSON_API_COLLECTION, [new ClassT(MeteredJsonApiResource::class)])),
                PaginatedJsonApiController::class.'::timacdonald' => $returns(new ClassT(JsonApiResourceCollection::class, [new ClassT(TimacdonaldArticleResource::class)])),
            ],
            traceOverrides: [
                PaginatedJsonApiController::class.'::firstParty' => TraceScript::forChain($chain, 'Illuminate\\Database\\Eloquent\\Builder'),
                PaginatedJsonApiController::class.'::timacdonald' => TraceScript::forChain($chain, 'Illuminate\\Database\\Eloquent\\Builder'),
            ],
        );
    };

    // The published schema with every `$ref` inlined, as a validator reads it.
    $this->inline = static function (array $document, mixed $schema): mixed {
        $inline = static function (mixed $node, ?string $key = null) use (&$inline, $document): mixed {
            if (! is_array($node)) {
                return $node;
            }
            if (isset($node['$ref']) && is_string($node['$ref'])) {
                return $inline($document['components']['schemas'][substr($node['$ref'], strlen('#/components/schemas/'))]);
            }
            if ($node === [] && ! in_array($key, ['required', 'enum'], true)) {
                return new stdClass;
            }
            $out = [];
            foreach ($node as $k => $v) {
                $out[$k] = $inline($v, is_string($k) ? $k : $key);
            }

            return array_is_list($out) && in_array($key, ['required', 'enum', 'type', 'examples', 'anyOf', 'allOf', 'oneOf'], true) ? $out : (object) $out;
        };

        return $inline($schema);
    };

    // A page with a page on either side of it, so every link that can be sent is.
    $this->page = static function (string $kind, array $items): mixed {
        return match ($kind) {
            'length' => new LengthAwarePaginator($items, 30, 2, 2),
            'simple' => new Paginator([...$items, ...$items], 2, 2),
            default => new CursorPaginator([...$items, ...$items], 2),
        };
    };
});

it('publishes a page of JSON:API resources as the envelope each family sends', function (string $family, string $kind): void {
    $action = $family === 'first-party' ? 'firstParty' : 'timacdonald';
    $document = localityBuild(
        static fn (Router $router) => $router->get('api/zz-json-api-pages', [PaginatedJsonApiController::class, $action]),
        ($this->engineFor)($this->chains[$kind]),
    )->document->toArray();

    $content = $document['paths']['/api/zz-json-api-pages']['get']['responses']['200']['content'];
    $schema = ($this->inline)($document, $content['application/vnd.api+json']['schema']);

    $model = new class extends Model {};
    $model->forceFill(['id' => 1, 'title' => 't']);
    $items = [$model, $model];
    $collection = $family === 'first-party'
        ? MeteredJsonApiResource::collection(($this->page)($kind, $items))
        : TimacdonaldArticleResource::collection(($this->page)($kind, $items));
    $send = static fn (JsonResource $resource, array $query = []): mixed => json_decode((string) $resource->toResponse(Request::create('/', 'GET', $query))->getContent());
    $sent = $send($collection);

    // Rejection controls, so an accepting run proves something: a body with no page members is not a
    // page, and a null link is not what timacdonald sends.
    $bare = (object) ['data' => $sent->data];
    $nulled = clone $sent;
    $nulled->links = (object) [...(array) $sent->links, 'prev' => null];
    // An item that is its attributes alone is what an older timacdonald release sends under Laravel 12.45
    // and later, so only there is it accepted — anywhere else a resource object carries its identity.
    $attributesOnly = clone $sent;
    $attributesOnly->data = [(object) ['title' => 't']];

    // The page selector the paginator reads rides the same trace, so the next page can be asked for.
    $parameters = array_column($document['paths']['/api/zz-json-api-pages']['get']['parameters'] ?? [], 'name');

    expect($parameters)->toContain($kind === 'cursor' ? 'cursor' : 'page')
        ->and($content)->toHaveKey('application/vnd.api+json')
        ->and(array_keys((array) $sent))->toBe(['data', 'links', 'meta'])
        ->and((new Validator)->validate($sent, $schema)->isValid())->toBeTrue()
        ->and((new Validator)->validate($bare, $schema)->isValid())->toBeFalse()
        ->and((new Validator)->validate($nulled, $schema)->isValid())->toBe($family === 'first-party')
        ->and((new Validator)->validate($attributesOnly, $schema)->isValid())->toBe($family === 'timacdonald' && ! timacdonaldSendsResourceObjects());
})->with(['first-party', 'timacdonald'])->with(['length', 'simple', 'cursor']);

it('publishes every page a family sends, with no page on one side, on either, or no records at all', function (string $family, string $kind): void {
    $action = $family === 'first-party' ? 'firstParty' : 'timacdonald';
    $document = localityBuild(
        static fn (Router $router) => $router->get('api/zz-json-api-pages', [PaginatedJsonApiController::class, $action]),
        ($this->engineFor)($this->chains[$kind]),
    )->document->toArray();
    $schema = ($this->inline)($document, $document['paths']['/api/zz-json-api-pages']['get']['responses']['200']['content']['application/vnd.api+json']['schema']);

    $model = new class extends Model {};
    $model->forceFill(['id' => 1, 'title' => 't']);
    $one = [$model];
    $pages = match ($kind) {
        'length' => ['empty' => new LengthAwarePaginator([], 0, 2, 1), 'only' => new LengthAwarePaginator($one, 1, 2, 1), 'first' => new LengthAwarePaginator($one, 3, 2, 1), 'last' => new LengthAwarePaginator($one, 3, 2, 2)],
        'simple' => ['empty' => new Paginator([], 2, 1), 'only' => new Paginator($one, 2, 1), 'first' => new Paginator([$model, $model, $model], 2, 1), 'last' => new Paginator($one, 2, 2)],
        default => ['empty' => new CursorPaginator([], 2), 'only' => new CursorPaginator($one, 2), 'first' => new CursorPaginator([$model, $model, $model], 2), 'last' => new CursorPaginator($one, 2, new Cursor(['id' => 1]))],
    };

    foreach ($pages as $name => $paginator) {
        $collection = $family === 'first-party' ? MeteredJsonApiResource::collection($paginator) : TimacdonaldArticleResource::collection($paginator);
        $sent = json_decode((string) $collection->toResponse(Request::create('/'))->getContent());

        expect((new Validator)->validate($sent, $schema)->isValid())->toBeTrue("{$name} page");
    }

    // A cursor page with no page either side is where timacdonald drops every link, which PHP sends as
    // `[]` — the population this proves, so it must be reached; and a list with a link in it is not.
    $only = json_decode((string) ($family === 'first-party' ? MeteredJsonApiResource::collection($pages['only']) : TimacdonaldArticleResource::collection($pages['only']))->toResponse(Request::create('/'))->getContent());
    $listed = clone $only;
    $listed->links = ['https://example.com'];

    expect($only->links === [])->toBe($family === 'timacdonald' && $kind === 'cursor')
        ->and((new Validator)->validate($listed, $schema)->isValid())->toBeFalse();
})->with(['first-party', 'timacdonald'])->with(['length', 'simple', 'cursor']);

it('publishes a link with() adds to timacdonald\'s page links as sent, with or without one of the page\'s', function (string $kind): void {
    $location = new SourceLocation('');
    $chain = $this->chains[$kind];
    $link = new ArrayShapeT([new ArrayShapeField('links', new ArrayShapeT([new ArrayShapeField('prev', ScalarT::string())]))]);
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        analysisOverrides: [
            TimacdonaldArticleResource::class.'::toAttributes' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([new ArrayShapeField('title', ScalarT::string())]), $location)]),
            LinkedTimacdonaldCollection::class.'::with' => new ActionAnalysis(returns: [new ReturnSite($link, $location), new ReturnSite(new ArrayShapeT([]), $location)]),
            PaginatedJsonApiController::class.'::linkedTimacdonald' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(LinkedTimacdonaldCollection::class, [new ClassT(TimacdonaldArticleResource::class)]), $location)]),
        ],
        traceOverrides: [PaginatedJsonApiController::class.'::linkedTimacdonald' => TraceScript::forChain($chain, 'Illuminate\\Database\\Eloquent\\Builder')],
    );
    $document = localityBuild(static fn (Router $router) => $router->get('api/zz-json-api-pages', [PaginatedJsonApiController::class, 'linkedTimacdonald']), $engine)->document->toArray();
    $schema = ($this->inline)($document, $document['paths']['/api/zz-json-api-pages']['get']['responses']['200']['content']['application/vnd.api+json']['schema']);

    $model = new class extends Model {};
    $model->forceFill(['id' => 1, 'title' => 't']);
    // Only a page with a page before it has a `prev` of its own, and only a cursor page may have no link.
    $pages = match ($kind) {
        'length' => ['only' => new LengthAwarePaginator([$model], 1, 2, 1), 'later' => new LengthAwarePaginator([$model], 30, 2, 2)],
        'simple' => ['only' => new Paginator([$model], 2, 1), 'later' => new Paginator([$model, $model, $model], 2, 2)],
        default => ['only' => new CursorPaginator([$model], 2), 'later' => new CursorPaginator([$model, $model, $model], 2, new Cursor(['id' => 1]))],
    };

    $bodies = [];
    foreach ($pages as $name => $paginator) {
        foreach (['plain' => [], 'archived' => ['archived' => '1']] as $asked => $query) {
            $sent = json_decode((string) (new LinkedTimacdonaldCollection($paginator, TimacdonaldArticleResource::class))->toResponse(Request::create('/', 'GET', $query))->getContent());
            $bodies["{$name} {$asked}"] = $sent;

            expect((new Validator)->validate($sent, $schema)->isValid())->toBeTrue("{$name} page, {$asked}");
        }
    }

    // Each case the merge can take is reached: with()'s link alone, merged with the page's into a list, and
    // — on a cursor page with none either side — no link at all.
    expect($bodies['only archived']->links->prev)->toBe('https://example.com/archive')
        ->and($bodies['later archived']->links->prev)->toBeArray()
        ->and($bodies['only plain']->links === [])->toBe($kind === 'cursor');
})->with(['length', 'simple', 'cursor']);

it('keeps Laravel\'s shared page parts for a first-party page, and names timacdonald\'s own', function (string $kind, string $laravel, string $timacdonald): void {
    $links = function (string $action) use ($kind): string {
        $document = localityBuild(
            static fn (Router $router) => $router->get('api/zz-json-api-pages', [PaginatedJsonApiController::class, $action]),
            ($this->engineFor)($this->chains[$kind]),
        )->document->toArray();
        $body = $document['paths']['/api/zz-json-api-pages']['get']['responses']['200']['content']['application/vnd.api+json']['schema'];
        // A page of open objects — what an older timacdonald release sends — is not named for its item.
        $envelope = $body['allOf'][0] ?? $body;
        $page = isset($envelope['$ref']) ? $document['components']['schemas'][substr($envelope['$ref'], strlen('#/components/schemas/'))] : $envelope;

        return substr($page['properties']['links']['$ref'], strlen('#/components/schemas/'));
    };

    expect($links('firstParty'))->toBe($laravel)
        ->and($links('timacdonald'))->toBe($timacdonald);
})->with([
    'length' => ['length', 'PaginationLinks', 'AvailablePaginationLinks'],
    'simple' => ['simple', 'SimplePaginationLinks', 'AvailableSimplePaginationLinks'],
    'cursor' => ['cursor', 'PaginationLinks', 'AvailableCursorPaginationLinks'],
]);
