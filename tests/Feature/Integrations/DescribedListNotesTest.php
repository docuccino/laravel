<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleJsonApiResource;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\DescribedListController;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\DescribedResourceController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\WrappedData;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Query parameters whose whole description is facts an integration recovered or a package's grammar
 * fixes — a Query Builder list's allowed values, separator and default, the separator of a JSON:API
 * `include` and `fields[TYPE]` and of a Data object's request partials, and what keeps a signed link's
 * signature valid — each with and without a description their author wrote. The Query Builder list is
 * read under the default comma, where its values are also an enum, and under another separator, where
 * its description is the only place that names them. No golden held one an author had described.
 */
function describedListNotesBuild(string $delimiter = ','): GenerationResult
{
    bootLaravelData(null);
    config()->set('query-builder.delimiter', $delimiter);

    $returns = static fn (string $class): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ClassT($class), new SourceLocation(''))]);
    $list = (string) (new ReflectionClass(DescribedListController::class))->getFileName();

    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        analysisOverrides: [
            DescribedResourceController::class.'::article' => $returns(ArticleJsonApiResource::class),
            DescribedResourceController::class.'::plainArticle' => $returns(ArticleJsonApiResource::class),
            DescribedResourceController::class.'::wrapped' => $returns(WrappedData::class),
            DescribedResourceController::class.'::plainWrapped' => $returns(WrappedData::class),
        ],
        traceOverrides: [
            DescribedListController::class.'::index' => TraceScript::forMethod($list, DescribedListController::class, 'index', receiverFqcn: 'Spatie\\QueryBuilder\\QueryBuilder'),
            DescribedListController::class.'::browse' => TraceScript::forMethod($list, DescribedListController::class, 'browse', receiverFqcn: 'Spatie\\QueryBuilder\\QueryBuilder'),
        ],
    );

    return localityBuild(static function (Router $router) use ($delimiter): void {
        $router->get('api/zz-gadget-lists', [DescribedListController::class, 'index']);
        $router->get('api/zz-gadget-lists/browse', [DescribedListController::class, 'browse']);
        if ($delimiter !== ',') {
            return;
        }

        $router->get('api/zz-articles/{id}', [DescribedResourceController::class, 'article']);
        $router->get('api/zz-articles/{id}/plain', [DescribedResourceController::class, 'plainArticle']);
        $router->get('api/zz-wrapped', [DescribedResourceController::class, 'wrapped']);
        $router->get('api/zz-wrapped/plain', [DescribedResourceController::class, 'plainWrapped']);
        $router->get('api/zz-links/{token}', [DescribedResourceController::class, 'confirm'])->middleware('signed');
        $router->get('api/zz-links/{token}/plain', [DescribedResourceController::class, 'plainConfirm'])->middleware('signed');
    }, $engine);
}

it('emits integration parameters their author described byte-identical to their committed golden', function (string $delimiter, string $golden): void {
    assertGolden($golden, (new UirEmitter)->emit(describedListNotesBuild($delimiter)->document));
})->with([
    'the comma' => [',', 'described-list-notes.uir.json'],
    'another separator' => ['|', 'described-list-notes-delimited.uir.json'],
]);

/**
 * The published description of one query parameter, or of one member of a deepObject parameter.
 *
 * @param  array<string, mixed>  $document
 */
function describedListNote(array $document, string $path, string $parameter): mixed
{
    return paramsByName($document['paths'][$path]['get'])[$parameter]['description'] ?? null;
}

/*
 * Each sentence states what the server does with the parameter whatever prose describes it: the values
 * the allow-list accepts and what `-` means, the separator a package splits the value on, the default
 * applied when it is left off, and that a signed link's signature only holds over the link as it was
 * minted. A reader cannot see the code to recover any of it, so an author's description leads and the
 * sentences stand beneath it as a paragraph of their own.
 */
it('keeps what an integration settles beneath the description an author writes', function (string $delimiter, string $path, string $parameter, string $expected): void {
    expect(describedListNote(emittedArray(describedListNotesBuild($delimiter)), $path, $parameter))->toBe($expected);
})->with([
    'a sort, under another separator' => ['|', '/api/zz-gadget-lists', 'sort', "How to order the list.\n\nSort by: starts_at, score (prefix `-` for descending). Values are separated by `|`. Defaults to `-starts_at`."],
    'an include, under another separator' => ['|', '/api/zz-gadget-lists', 'include', "Related records to embed.\n\nInclude related resources: beacon. Values are separated by `|`."],
    'fields, under another separator' => ['|', '/api/zz-gadget-lists', 'fields', "The gadget columns to return.\n\nFields to return: id, score. Values are separated by `|`."],
    'a type\'s fields, under another separator' => ['|', '/api/zz-gadget-lists', 'fields[beacon]', "The beacon columns to return.\n\nFields to return: id. Values are separated by `|`."],
    'a sort, under the comma' => [',', '/api/zz-gadget-lists', 'sort', "How to order the list.\n\nSort by: starts_at, score (prefix `-` for descending)."],
    'a JSON:API include' => [',', '/api/zz-articles/{id}', 'include', "Related records to embed.\n\nValues are separated by `,`."],
    'a JSON:API sparse fieldset' => [',', '/api/zz-articles/{id}', 'fields', "The attributes to return, per type.\n\nEach type's fields are separated by `,`."],
    'a Data include partial' => [',', '/api/zz-wrapped', 'include', "Optional properties to add.\n\nValues are separated by `,`."],
    'a Data only partial' => [',', '/api/zz-wrapped', 'only', "The only properties to return.\n\nValues are separated by `,`."],
    'a signed link\'s signature' => [',', '/api/zz-links/{token}', 'signature', "Proof the link came from us.\n\nSend it, and the link's other query parameters, as the link carries them."],
]);

it('publishes each integration\'s description alone where nobody described the parameter', function (string $delimiter, string $path, string $parameter, string $expected): void {
    expect(describedListNote(emittedArray(describedListNotesBuild($delimiter)), $path, $parameter))->toBe($expected);
})->with([
    'a sort, under another separator' => ['|', '/api/zz-gadget-lists/browse', 'sort', 'Sort by: starts_at, score (prefix `-` for descending). Values are separated by `|`. Defaults to `-starts_at`.'],
    'a JSON:API include' => [',', '/api/zz-articles/{id}/plain', 'include', 'Comma-separated list of relationships to include as compound-document data.'],
    'a Data include partial' => [',', '/api/zz-wrapped/plain', 'include', 'Comma-separated list of lazy/optional properties to include in the response.'],
    'a signed link\'s signature' => [',', '/api/zz-links/{token}/plain', 'signature', "Signature of the signed link this request is made from. Send it, and the link's other query parameters, as the link carries them."],
]);
