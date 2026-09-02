<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * One 401 problem document, reached two ways: an arm that writes its words out and an arm that asks the
 * exception for them. Same carrier, same status, same media type, same title — so the two responses are
 * one contract, illustrated once by a body the build read in full and once by a body it could only read
 * in part. This is the commonest reason an error carries a member nothing folded.
 */
final class PortalProblemRenderer
{
    public function __invoke(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof SessionExpiredException => $this->problem(
                'https://example.com/problems/session-expired',
                'Sign in again to continue.',
            ),
            $e instanceof CredentialsRejectedException => $this->problem($e->problemType(), $e->getMessage()),
            default => null,
        };
    }

    private function problem(string $type, string $detail): JsonResponse
    {
        return (new PortalProblem(
            type: $type,
            title: 'Unauthenticated',
            status: 401,
            detail: $detail,
        ))->toProblemResponse();
    }
}
