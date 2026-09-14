<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;

/**
 * One rule for a value a producer publishes that came from the build environment rather than from
 * something the document pins: emit it, and say so. Never refuse and never omit — these values are
 * contract-bearing (OAS requires a `tokenUrl` on every flow object, and an `apiKey`-in-cookie scheme
 * with the wrong `name` sends the client's request without the cookie), and a local preview has to keep
 * working. Which signal each kind of value is judged on, and why the tier is Warning: design §9.
 *
 * It also owns the one rule that holds for a published URL whatever it was read from: it carries no
 * credentials. That rule sits here because it is the same reading — {@see userinfo()} answers both the
 * strip and the redaction a message needs — and because a second reader of a URL's userinfo is a
 * second answer to "does this one carry credentials".
 */
final class MachineDependentValue
{
    public const CODE = 'config.machine-dependent-value';

    public const CREDENTIALS_CODE = 'config.url-credentials-removed';

    /**
     * Hosts that name the build machine by NAME. Every numeric spelling is decided by
     * {@see loopbackAddress()} instead: `127.1`, `2130706433` and `0177.0.0.1` are all the same machine
     * as `127.0.0.1`, and no table can hold them all.
     *
     * @var list<string>
     */
    private const LOOPBACK_HOSTS = ['localhost'];

    /**
     * Suffixes reserved for local development and documentation (RFC 6761/2606, mDNS `.local`, and the
     * `.localdomain` a stock `/etc/hosts` carries), so a host under one of them resolves on somebody's
     * laptop and nowhere else.
     *
     * @var list<string>
     */
    private const LOCAL_SUFFIXES = ['.localhost', '.test', '.local', '.example', '.localdomain'];

    /**
     * Whether `$url`'s host names the build machine or a development-only name. Anything without a
     * host — a bare path, a template, a value that is not a URL at all — is not local: there is no
     * evidence either way, and guessing would report a public API as machine-dependent.
     */
    public static function isLocalUrl(string $url): bool
    {
        $host = HostAddress::of($url);
        if ($host === null) {
            return false;
        }

        $address = self::loopbackAddress($host);
        if ($address !== null) {
            return $address;
        }

        if (in_array($host, self::LOOPBACK_HOSTS, true)) {
            return true;
        }

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The report for an unpinned URL read from `$configKey`, or null when the URL is a fine thing to
     * publish. Any producer resolving a URL out of framework config gets the rule by calling this.
     */
    public static function forUrl(string $subject, string $url, string $configKey, string $pin, ?string $routeSignature = null): ?Diagnostic
    {
        if (! self::isLocalUrl($url)) {
            return null;
        }

        return self::report(
            sprintf(
                "%s publishes '%s', read from the application's '%s' — its host names the machine this build ran on, so the document sends every client to a host only that machine can reach, and a build elsewhere emits different bytes.",
                $subject,
                self::redact($url),
                $configKey,
            ),
            self::pinHelp($pin),
            $routeSignature,
        );
    }

    /**
     * The report for a URL whose host the route itself names (`Route::domain(...)`), or null when that
     * host is a fine thing to publish. No config key answered for it and no docuccino option overrides
     * it, so the help names the two ways out instead of a pin — and says which of them settles this,
     * since an overlay is applied after the build and so corrects the document without clearing the
     * report that named it.
     */
    public static function forHost(string $subject, string $url, string $routeSignature): ?Diagnostic
    {
        if (! self::isLocalUrl($url)) {
            return null;
        }

        return self::report(
            sprintf(
                "%s publishes '%s' — its host names the machine this build ran on, so the document sends every client to a host only that machine can reach, and a build elsewhere emits different bytes.",
                $subject,
                self::redact($url),
            ),
            "Bind the route to the host clients actually use, which is what settles this. Rewriting the operation's servers with an overlay makes the document say the same thing wherever it is built, and this warning keeps naming the route.",
            $routeSignature,
        );
    }

    /**
     * The report for an unpinned opaque value read from `$configKey` — no host to inspect, so the
     * value is taken at face value and what is reported is where it came from.
     */
    public static function forValue(string $subject, string $value, string $configKey, string $pin, ?string $routeSignature = null): Diagnostic
    {
        return self::report(
            sprintf(
                "%s publishes '%s', read from the application's '%s', which the framework derives from the environment — so the output becomes machine-dependent: the same code documented elsewhere publishes a different value, and a client acting on the wrong one is rejected.",
                $subject,
                $value,
                $configKey,
            ),
            self::pinHelp($pin),
            $routeSignature,
        );
    }

    /**
     * The report for a value `$configKey` answered nothing for, so a hard-coded default stood in. The
     * emitted value is still true of this build — it is what the application is configured with — but
     * nothing chose it.
     */
    public static function forDefault(string $subject, string $value, string $configKey, string $pin, ?string $routeSignature = null): Diagnostic
    {
        return self::report(
            sprintf(
                "%s publishes '%s', which no '%s' supplied — the value is a fallback default, so it describes no deployment and the output becomes machine-dependent.",
                $subject,
                $value,
                $configKey,
            ),
            self::pinHelp($pin),
            $routeSignature,
        );
    }

    /**
     * `$url` as a document may publish it: the same URL with any `user:pass@` removed. A credential is
     * no part of an endpoint's identity — `https://api.example.com/oauth/token` is the address, and the
     * userinfo in front of it is a secret riding into an artifact whose readers do not control it — so
     * the publisher degrades by WIDENING to the address alone rather than by refusing a URL that is
     * otherwise correct. A URL carrying none comes back byte-identical, including one `parse_url`
     * refuses outright.
     */
    public static function withoutCredentials(string $url): string
    {
        return self::replaceUserinfo($url, '');
    }

    /**
     * Whether `$url` carries credentials at all. Every rule that judges a URL's userinfo asks this one,
     * so a guard cannot recognise fewer spellings than the strip beside it.
     */
    public static function carriesCredentials(string $url): bool
    {
        return self::userinfo($url) !== null;
    }

    /**
     * The report for a URL a producer had to take credentials out of, or null when it carried none —
     * so it is non-null exactly when {@see withoutCredentials()} changes something. `$source` is the
     * place the author goes and edits, named as prose rather than as a key, because a route's own
     * `Route::domain()` is one of them and no config key answers for it. It fires wherever the value
     * came from, `app.url` or a docuccino pin, because the leak is the same either way. What it quotes
     * is the URL as PUBLISHED — the author is told what the document now says, and quoting the value as
     * it arrived would move the secret out of one committed artifact and into another.
     */
    public static function forCredentials(string $subject, string $url, string $source, ?string $routeSignature = null): ?Diagnostic
    {
        if (! self::carriesCredentials($url)) {
            return null;
        }

        // Constructed here rather than through report(), which owns the machine-dependent code: the
        // scan behind the diagnostics reference reads the constructor's `code:` argument, so a code
        // reaching it through a parameter is a code the reference page can go stale on unnoticed.
        return new Diagnostic(
            severity: Severity::Warning,
            code: self::CREDENTIALS_CODE,
            message: sprintf(
                "%s: the URL read from %s carries HTTP credentials in front of its host, so they were removed and '%s' is published instead. A document is handed to people who do not have that secret and should not be given it, and an endpoint's address needs nothing that was in it.",
                $subject,
                $source,
                self::withoutCredentials($url),
            ),
            routeSignature: $routeSignature,
            help: sprintf(
                "Take the 'user:password@' out of %s. Where the endpoint really is behind HTTP authentication, say so as a security scheme — that is the member a client reads for it, and a URL cannot carry it.",
                $source,
            ),
        );
    }

    /**
     * Whether a host that IS an address names the build machine: anything in `127.0.0.0/8`, the
     * unspecified address, the IPv6 loopback, and the IPv4-mapped spelling of either. Null when the
     * host is a name rather than an address, so it falls through to the tables above.
     */
    private static function loopbackAddress(string $host): ?bool
    {
        $address = HostAddress::ipv4($host);
        if ($address !== null) {
            return self::isLoopbackV4($address);
        }

        $packed = HostAddress::ipv6($host);
        if ($packed === null) {
            return null;
        }

        if ($packed === str_repeat("\0", 15)."\x01" || $packed === str_repeat("\0", 16)) {
            return true;
        }

        $mapped = HostAddress::mappedIpv4($packed);

        return $mapped !== null && self::isLoopbackV4($mapped);
    }

    private static function isLoopbackV4(int $address): bool
    {
        return $address === 0 || ($address >> 24) === 127;
    }

    /**
     * The URL with any `user:pass@` blanked out. These messages land in CI logs, and an `APP_URL` that
     * carries credentials is exactly the kind of value this rule exists to report.
     */
    private static function redact(string $url): string
    {
        return self::replaceUserinfo($url, '***@');
    }

    /**
     * The `user[:pass]@` `$url` carries in front of its host, spelled as the URL spells it, with the
     * offset it starts at — or null where the URL carries none, which includes one `parse_url` refuses
     * to read at all. The ONE reading of a URL's credentials: the strip a publisher owes, the redaction
     * a message owes and the check a server URL is refused on all ask this, so none of them can
     * recognise a spelling another misses.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function userinfo(string $url): ?array
    {
        $user = parse_url($url, PHP_URL_USER);
        if (! is_string($user)) {
            return null;
        }

        $password = parse_url($url, PHP_URL_PASS);
        $userinfo = $user.(is_string($password) ? ':'.$password : '');

        $at = strpos($url, $userinfo.'@');

        return $at === false ? null : [$userinfo, $at];
    }

    /** `$url` with its userinfo and the `@` after it replaced by `$with`, or unchanged where it has none. */
    private static function replaceUserinfo(string $url, string $with): string
    {
        $userinfo = self::userinfo($url);

        return $userinfo === null ? $url : substr_replace($url, $with, $userinfo[1], strlen($userinfo[0]) + 1);
    }

    private static function pinHelp(string $pin): string
    {
        return sprintf(
            "Set docuccino's '%s' to the value clients should be given, so the document says the same thing wherever it is built.",
            $pin,
        );
    }

    private static function report(string $message, string $help, ?string $routeSignature): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: self::CODE,
            message: $message,
            routeSignature: $routeSignature,
            help: $help,
        );
    }
}
