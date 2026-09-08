<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Middleware;

use Illuminate\Auth\Middleware\Authenticate;

/**
 * The application's own authenticator, registered under the `auth` alias: what the Laravel ≤10 skeleton
 * ships in `app/Http/Middleware/Authenticate.php`, and what every application upgraded from one still
 * carries. It is a plain subclass — the skeleton's only override is `redirectTo()` — so nothing about
 * the shape here is invented for the test.
 */
final class ApplicationAuthenticate extends Authenticate {}
