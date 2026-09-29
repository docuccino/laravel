<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RouteConstraints;

use Docuccino\Attributes\OperationId;

/** An action behind an optional segment that declares its own operationId. */
final class DeclaredIdController
{
    #[OperationId('pages.fetch')]
    public function show(?string $page = null): array
    {
        return ['page' => $page];
    }
}
