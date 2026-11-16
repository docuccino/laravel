<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FrameworkResponses\Package;

use Symfony\Component\HttpFoundation\JsonResponse;

/** A package's gateway, handing back Symfony's JSON response as it fetched it. */
class LegacyGateway
{
    public function fetch(int $id): JsonResponse
    {
        return new JsonResponse(['id' => $id]);
    }
}
