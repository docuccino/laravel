<?php

declare(strict_types=1);

namespace Workbench\App\Services;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Workbench\App\Exceptions\ExportLockedException;

/** Guard helpers on a service the container injects into an action, each written to return `never` and `void`. */
class RequestGuard
{
    public function neverHttp(): never
    {
        throw new HttpException(409, 'The export already exists.');
    }

    public function voidHttp(): void
    {
        throw new HttpException(409, 'The export already exists.');
    }

    public function neverAbort(): never
    {
        abort(404);
    }

    public function voidAbort(): void
    {
        abort(404);
    }

    public function neverCustom(): never
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }

    public function voidCustom(): void
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }

    public function neverValidation(): never
    {
        throw ValidationException::withMessages(['name' => 'The name is taken.']);
    }

    public function voidValidation(): void
    {
        throw ValidationException::withMessages(['name' => 'The name is taken.']);
    }

    public function neverCarried(): never
    {
        throw new HttpResponseException(response()->json(['error' => 'slow down'], 429));
    }

    public function voidCarried(): void
    {
        throw new HttpResponseException(response()->json(['error' => 'slow down'], 429));
    }
}
