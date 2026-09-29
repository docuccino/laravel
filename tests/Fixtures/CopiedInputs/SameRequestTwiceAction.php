<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/** Is handed the package's request twice, and merges through the other name. */
final class SameRequestTwiceAction
{
    use AsAction;

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function prepareForValidation(ActionRequest $request, ActionRequest $same): void
    {
        $same->merge(['key' => $same->header('Idempotency-Key')]);
        $request->merge(['key' => 'overwritten']);
    }

    public function handle(): void {}
}
