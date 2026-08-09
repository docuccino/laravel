<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\LaravelActions;

use Lorisleiva\Actions\Concerns\AsAction;

/**
 * A laravel-actions action that defines `jsonResponse()` the way the package documents it: `handle()`
 * produces a value, `jsonResponse()` wraps it into the JSON wire shape the client actually receives.
 * The two carry DISTINCT shapes so a `200` body built from the envelope (not `handle()`'s bare object)
 * proves the success-body redirect.
 */
final class JsonResponseAction
{
    use AsAction;

    /**
     * Publish an article.
     *
     * @return array{id: int}
     */
    public function handle(): array
    {
        return ['id' => 1];
    }

    /**
     * @param  array{id: int}  $article
     * @return array{data: array{id: int}, meta: array{published: bool}}
     */
    public function jsonResponse(array $article): array
    {
        return ['data' => $article, 'meta' => ['published' => true]];
    }
}
