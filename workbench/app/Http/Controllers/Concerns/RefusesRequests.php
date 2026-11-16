<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Workbench\App\Exceptions\ExportLockedException;

/** Guard helpers a controller takes from a trait, each written to return `never` and `void`. */
trait RefusesRequests
{
    protected function sharedNeverHttp(): never
    {
        throw new HttpException(409, 'The export already exists.');
    }

    protected function sharedVoidHttp(): void
    {
        throw new HttpException(409, 'The export already exists.');
    }

    protected function sharedNeverAbort(): never
    {
        abort(404);
    }

    protected function sharedVoidAbort(): void
    {
        abort(404);
    }

    protected function sharedNeverCustom(): never
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }

    protected function sharedVoidCustom(): void
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }

    protected function sharedNeverValidation(): never
    {
        throw ValidationException::withMessages(['name' => 'The name is taken.']);
    }

    protected function sharedVoidValidation(): void
    {
        throw ValidationException::withMessages(['name' => 'The name is taken.']);
    }

    protected function sharedNeverCarried(): never
    {
        throw new HttpResponseException(response()->json(['error' => 'slow down'], 429));
    }

    protected function sharedVoidCarried(): void
    {
        throw new HttpResponseException(response()->json(['error' => 'slow down'], 429));
    }
}
