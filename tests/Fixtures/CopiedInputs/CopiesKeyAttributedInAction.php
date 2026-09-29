<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Docuccino\Attributes\Description;
use Lorisleiva\Actions\ActionRequest;

/** An attributed action hook shared between actions. */
trait CopiesKeyAttributedInAction
{
    #[Description('Copies the idempotency key into the input.')]
    public function prepareForValidation(ActionRequest $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);
    }
}
