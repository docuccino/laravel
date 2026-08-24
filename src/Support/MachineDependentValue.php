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
 */
final class MachineDependentValue
{
    public const CODE = 'config.machine-dependent-value';

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
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        // Trailing dot = the fully-qualified spelling of the same name; brackets = IPv6 URL syntax.
        $host = trim(strtolower($host), '[].');

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
     * Whether a host that IS an address names the build machine: anything in `127.0.0.0/8`, the
     * unspecified address, the IPv6 loopback, and the IPv4-mapped spelling of either. Null when the
     * host is a name rather than an address, so it falls through to the tables above.
     */
    private static function loopbackAddress(string $host): ?bool
    {
        $address = self::ipv4($host);
        if ($address !== null) {
            return self::isLoopbackV4($address);
        }

        $packed = inet_pton($host);
        if (! is_string($packed) || strlen($packed) !== 16) {
            return null;
        }

        if ($packed === str_repeat("\0", 15)."\x01" || $packed === str_repeat("\0", 16)) {
            return true;
        }

        if (! str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return false;
        }

        $mapped = unpack('N', substr($packed, 12));

        return is_array($mapped) && is_int($mapped[1] ?? null) && self::isLoopbackV4($mapped[1]);
    }

    private static function isLoopbackV4(int $address): bool
    {
        return $address === 0 || ($address >> 24) === 127;
    }

    /**
     * A host spelled as an IPv4 address, as the 32-bit number it means — in every spelling the C
     * resolver accepts, because `127.1`, `2130706433`, `0x7f.1` and `0177.0.0.1` all reach the same
     * machine and a document naming one of those is as unreachable as one naming `127.0.0.1`. Null
     * when the host is a name.
     */
    private static function ipv4(string $host): ?int
    {
        $parts = explode('.', $host);
        if (count($parts) > 4) {
            return null;
        }

        $values = [];
        foreach ($parts as $part) {
            $value = self::number($part);
            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        // The last part fills every byte the earlier ones left over; each earlier one is a single byte.
        $last = array_pop($values);
        if ($last >= 2 ** (8 * (4 - count($values)))) {
            return null;
        }

        $address = $last;
        foreach ($values as $index => $value) {
            if ($value > 255) {
                return null;
            }

            $address |= $value << (8 * (3 - $index));
        }

        return $address;
    }

    /** One dotted part as the number it spells: `0x…` hex, leading-zero octal, else decimal. */
    private static function number(string $part): ?int
    {
        if (str_starts_with($part, '0x')) {
            $digits = substr($part, 2);

            return $digits !== '' && strlen($digits) <= 8 && ctype_xdigit($digits) ? (int) hexdec($digits) : null;
        }

        if (strlen($part) > 1 && $part[0] === '0') {
            return strlen($part) <= 12 && strspn($part, '01234567') === strlen($part) ? (int) octdec($part) : null;
        }

        return $part !== '' && strlen($part) <= 10 && ctype_digit($part) ? (int) $part : null;
    }

    /**
     * The URL with any `user:pass@` blanked out. These messages land in CI logs, and an `APP_URL` that
     * carries credentials is exactly the kind of value this rule exists to report.
     */
    private static function redact(string $url): string
    {
        $parts = parse_url($url);
        $user = is_array($parts) ? ($parts['user'] ?? null) : null;
        if (! is_string($user)) {
            return $url;
        }

        $password = is_array($parts) ? ($parts['pass'] ?? null) : null;
        $userinfo = $user.(is_string($password) ? ':'.$password : '');

        $at = strpos($url, $userinfo.'@');

        return $at === false ? $url : substr_replace($url, '***@', $at, strlen($userinfo) + 1);
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
