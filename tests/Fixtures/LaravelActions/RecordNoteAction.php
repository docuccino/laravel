<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\LaravelActions;

use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/** An action that validates a header with its own rules, by copying it into the input its rules read. */
final class RecordNoteAction
{
    use AsAction;

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'key' => 'required|uuid',
            'title' => 'required|string',
        ];
    }

    public function prepareForValidation(ActionRequest $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);
    }

    /**
     * Record a note.
     *
     * @return array<string, int>
     */
    public function asController(ActionRequest $request): array
    {
        return ['id' => 1];
    }
}
