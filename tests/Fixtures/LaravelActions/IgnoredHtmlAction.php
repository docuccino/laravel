<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\LaravelActions;

use Docuccino\Attributes\IgnoreResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * {@see HtmlResponseAction} with its 200 dropped: the `text/html` representation the decorator serves
 * is additive to that status, so an action that drops the status drops both representations with it.
 */
#[IgnoreResponse(status: 200)]
final class IgnoredHtmlAction
{
    use AsAction;

    /**
     * Show an article.
     *
     * @return array{id: int}
     */
    public function handle(): array
    {
        return ['id' => 1];
    }

    public function htmlResponse(mixed $article, Request $request): View
    {
        return view('articles.show', ['article' => $article]);
    }
}
