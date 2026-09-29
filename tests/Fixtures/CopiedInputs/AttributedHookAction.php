<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Docuccino\Attributes\Description;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/** An action whose hook and dispatched method both carry attributes on the line above. */
final class AttributedHookAction
{
    use AsAction;

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    #[Description('Copies the idempotency key into the input.')]
    public function prepareForValidation(ActionRequest $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);
    }

    #[Description('Records a note.')]
    public function handle(): void {}
}
