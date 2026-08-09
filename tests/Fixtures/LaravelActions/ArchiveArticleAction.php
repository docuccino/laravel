<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\LaravelActions;

use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\Concerns\AsController;

/**
 * A laravel-actions action that defines an explicit `asController()` entrypoint, so an invokable
 * registration resolves to `asController()` (which wins over `handle()`). Uses the `AsController`
 * trait directly rather than the umbrella `AsAction`.
 */
final class ArchiveArticleAction
{
    use AsController;

    /**
     * Archive an article.
     */
    public function asController(): JsonResponse
    {
        return new JsonResponse(['archived' => true]);
    }

    public function handle(): bool
    {
        return true;
    }
}
