<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Lorisleiva\Actions\ActionRequest;

/** An action hook shared between actions. */
trait CopiesKeyInAction
{
    public function prepareForValidation(ActionRequest $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);
    }
}
