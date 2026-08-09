<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\LaravelActions;

use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Concerns\WithAttributes;

/**
 * A laravel-actions action that opts out of automatic request validation via the `WithAttributes`
 * trait. The package's `ControllerDecorator::shouldValidateRequest()` returns false for such actions,
 * so `rules()`/`authorize()` never run even though the route dispatches through `handle()` — the
 * integration must not document a request body or a 403 for it.
 */
final class WithAttributesAction
{
    use AsAction;
    use WithAttributes;

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
     * Handle the request.
     *
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return ['id' => 1];
    }
}
