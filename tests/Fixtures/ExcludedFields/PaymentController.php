<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ExcludedFields;

/** Takes a {@see StorePaymentRequest}. */
final class PaymentController
{
    public function store(StorePaymentRequest $request): void {}
}
