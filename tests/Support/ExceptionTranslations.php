<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * An exception mapper written as a method — `$exceptions->map(ModelNotFoundException::class,
 * (new ExceptionTranslations)->forbid(...))` — so the reflector must locate it as that method.
 */
final class ExceptionTranslations
{
    public function forbid(ModelNotFoundException $e): AuthorizationException
    {
        return new AuthorizationException($e->getMessage());
    }
}
