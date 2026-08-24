<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Tests\Fixtures\Pagination\PagesController;

/**
 * What the analyser answers for {@see PagesController}: the item type each action's collection carries
 * and the paginating terminal its chain reaches. A support class rather than helpers in a test file so
 * the pagination suite and the locality row script the same document.
 */
final class PaginationEngine
{
    public const ARTICLE_RESOURCE = 'Docuccino\\Laravel\\Tests\\Fixtures\\ApiResources\\ArticleResource';

    public const AUTHOR_RESOURCE = 'Docuccino\\Laravel\\Tests\\Fixtures\\ApiResources\\AuthorResource';

    /** A resource class no `toArray` is scripted for, so its schema degrades instead of hoisting. */
    public const OPAQUE_RESOURCE = 'Docuccino\\Laravel\\Tests\\Fixtures\\Pagination\\OpaqueResource';

    /** Action name → the terminal its chain ends on. */
    public const TERMINALS = [
        'articles' => 'paginate',
        'moreArticles' => 'paginate',
        'simpleArticles' => 'simplePaginate',
        'cursorArticles' => 'cursorPaginate',
        'authors' => 'paginate',
        'shapedItems' => 'paginate',
        'unexpandable' => 'paginate',
    ];

    public static function make(): StubTypeEngine
    {
        $location = new SourceLocation('');
        $collection = static fn (DType $item): ClassT => new ClassT(
            'Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection',
            [$item],
        );

        $items = [
            'articles' => new ClassT(self::ARTICLE_RESOURCE),
            'moreArticles' => new ClassT(self::ARTICLE_RESOURCE),
            'simpleArticles' => new ClassT(self::ARTICLE_RESOURCE),
            'cursorArticles' => new ClassT(self::ARTICLE_RESOURCE),
            'authors' => new ClassT(self::AUTHOR_RESOURCE),
            'shapedItems' => new ArrayShapeT([new ArrayShapeField('id', ScalarT::int())]),
            'unexpandable' => new ClassT(self::OPAQUE_RESOURCE),
        ];

        $analyses = [];
        $traces = [];
        foreach (self::TERMINALS as $action => $terminal) {
            $symbol = PagesController::class.'::'.$action;
            $analyses[$symbol] = new ActionAnalysis(returns: [new ReturnSite($collection($items[$action]), $location)]);
            $traces[$symbol] = TraceScript::forChain(
                '$q->'.$terminal.'(15)',
                'Illuminate\\Database\\Eloquent\\Builder',
            );
        }

        return WorkbenchEngine::make(analysisOverrides: $analyses, traceOverrides: $traces);
    }

    /** @return callable(): TypeEngine */
    public static function factory(): callable
    {
        return static fn (): TypeEngine => self::make();
    }
}
