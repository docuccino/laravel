<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Laravel\Support\MachineDependentValue;
use Illuminate\Support\Str;

/**
 * Which cookie name the stateful scheme publishes, and whether that name is one the application chose.
 *
 * Sanctum's stateful cookie *is* the Laravel session cookie, so: the document's own pin, else the app's
 * real `session.cookie`, else Laravel's own default name.
 *
 * The report is gated on the value's SHAPE, and that gate is the whole point. Laravel's shipped
 * `config/session.php` reads `env('SESSION_COOKIE', Str::slug(env('APP_NAME'), '_').'_session')`, so a
 * name equal to what that expression produces here is one the environment supplied — renaming the app
 * changes the document. A name that is anything else was written into `config/session.php` by hand, and
 * warning about it would be warning about correct code. It is emitted either way, because it is
 * contract-bearing: an `apiKey`-in-cookie scheme naming the wrong cookie makes every client send the
 * wrong one.
 */
final class SanctumCookie
{
    /** What the extension publishes when nothing — not even `session.cookie` — answers. */
    public const FALLBACK = 'laravel_session';

    /** What a machine-dependent-value report names, since the scheme's own slot isn't settled yet. */
    private const PUBLISHED = "The Sanctum stateful scheme's cookie name";

    private const PIN = 'integrations.sanctum.cookie';

    /** The cookie name the stateful scheme publishes. */
    public static function resolve(mixed $pinned, mixed $sessionCookie): string
    {
        if (is_string($pinned) && $pinned !== '') {
            return $pinned;
        }

        return is_string($sessionCookie) && $sessionCookie !== '' ? $sessionCookie : self::FALLBACK;
    }

    /**
     * The report for a published name nothing pinned and nothing chose, or null when the application
     * chose it — a pin, or a `config/session.php` that states a name of its own.
     */
    public static function report(mixed $pinned, mixed $sessionCookie, mixed $appName): ?Diagnostic
    {
        if (is_string($pinned) && $pinned !== '') {
            return null;
        }

        if (! is_string($sessionCookie) || $sessionCookie === '') {
            return MachineDependentValue::forDefault(self::PUBLISHED, self::FALLBACK, 'session.cookie', self::PIN);
        }

        return $sessionCookie === self::environmentDefault($appName)
            ? MachineDependentValue::forValue(self::PUBLISHED, $sessionCookie, 'session.cookie', self::PIN)
            : null;
    }

    /** The name Laravel's shipped `config/session.php` expression produces in THIS environment. */
    private static function environmentDefault(mixed $appName): string
    {
        return Str::slug(is_string($appName) ? $appName : '', '_').'_session';
    }
}
