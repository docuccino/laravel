<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\LaravelActions;

use Lorisleiva\Actions\Concerns\AsAction;

/**
 * A laravel-actions action registered against an EXPLICIT method (`[ExplicitMethodAction::class,
 * 'store']`). The package only runs `rules()`/`authorize()` for its non-explicit dispatch methods
 * (`asController`/`handle`/`__invoke`), so despite defining both, this action validates NOTHING at
 * runtime through `store()` — the integration must not document a request body or a 403 for it.
 */
final class ExplicitMethodAction
{
    use AsAction;

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:100',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Store an article.
     *
     * @return array<string, mixed>
     */
    public function store(): array
    {
        return ['id' => 1];
    }
}
