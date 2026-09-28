<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Illuminate\Routing\Middleware\ValidateSignature;

/**
 * An application's own signed-URL check, the shape the pre-11 skeleton shipped: the framework's
 * middleware with the tracking parameters a mail client appends left out of the signature.
 */
final class ValidateLinkSignature extends ValidateSignature
{
    /** @var array<int, string> */
    protected $except = [
        'utm_source',
        'utm_campaign',
    ];
}
