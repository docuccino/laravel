<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Illuminate\Http\Request;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsAction;

/** Merges through a request it holds, which shares the input's JSON bag. */
final class HeldRequestAction
{
    use AsAction;

    public function __construct(private readonly Request $base) {}

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['key' => 'required|uuid'];
    }

    public function prepareForValidation(ActionRequest $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);
        $this->base->merge(['key' => 'overwritten']);
    }

    public function handle(): void {}
}
