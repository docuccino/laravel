<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\LaravelActions;

use Docuccino\Attributes\IgnoreResponse;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * {@see PublishArticleAction} with the 403 its `authorize()` gate produces dropped — the status the
 * package's controller decorator answers with, which no throw in `handle()` ever shows.
 */
#[IgnoreResponse(status: 403)]
final class IgnoredAuthorizeAction
{
    use AsAction;

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return ['title' => 'required|string|max:100'];
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
