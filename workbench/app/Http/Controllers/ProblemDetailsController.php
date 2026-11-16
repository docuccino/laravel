<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Workbench\App\Exceptions\OrderConflictException;
use Workbench\App\Exceptions\Rfc9457\Problem;
use Workbench\App\Exceptions\Rfc9457\ProblemException;

/** One error of each kind the RFC 9457 handler in `Exceptions\Rfc9457` answers. */
final class ProblemDetailsController
{
    public function conflict(): JsonResponse
    {
        throw new OrderConflictException('The order changed while you were editing it.');
    }

    public function invalid(): JsonResponse
    {
        throw ValidationException::withMessages(['quantity' => 'The quantity must be at least 1.']);
    }

    public function unauthenticated(): JsonResponse
    {
        throw new AuthenticationException;
    }

    public function missing(): JsonResponse
    {
        throw new NotFoundHttpException('No such order.');
    }

    public function carried(): JsonResponse
    {
        throw new ProblemException(new Problem(423, 'Locked', 'The order is being fulfilled.'));
    }

    public function failed(): JsonResponse
    {
        throw new RuntimeException('The ledger is unreachable.');
    }
}
