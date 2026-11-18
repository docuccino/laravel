<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * An argument the application refuses with a status of its own, which its `render()` answers: PHP calls the
 * class a defect, and the application has still given it a response. Inert — never dispatched.
 */
final class UnsupportedRadiusException extends InvalidArgumentException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
