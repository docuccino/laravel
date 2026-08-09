<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\LaravelActions;

use Lorisleiva\Actions\Concerns\AsAction;

/**
 * A laravel-actions action registered invokably (no `asController()`), so the route resolves to
 * `handle()`. Defines `rules()` (documented as the request body) and `authorize()` (documented as a
 * 403). Only ever reflected; `handle()`'s return shape is supplied by the stub engine in tests.
 */
final class PublishArticleAction
{
    use AsAction;

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:100',
            'body' => 'required|string',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Publish an article.
     *
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return ['id' => 1];
    }
}
