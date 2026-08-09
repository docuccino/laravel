<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\LaravelActions;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * A laravel-actions action that renders HTML for browser clients via `htmlResponse()` (the package's
 * controller decorator returns it for non-JSON requests). Its endpoint therefore serves `text/html`
 * alongside its JSON form — documented as a content-type note, not a JSON-typed body.
 */
final class HtmlResponseAction
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
