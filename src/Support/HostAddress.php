<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

/**
 * A URL's host, and the address it spells. Grammar only, no policy: every rule that judges a host
 * reads it through here, so a rule cannot recognise fewer spellings than the rule beside it —
 * `0xc0a80132`, `0177.0.0.1` and `[::ffff:192.168.1.50]` each name a host a dotted-quad check
 * would wave straight through.
 *
 * @internal
 */
final class HostAddress
{
    /**
     * The host of `$url`, lower-cased and stripped of the two spellings that decorate it: the
     * brackets IPv6 is carried in, and the trailing dot of a fully-qualified name. Null when the
     * value carries no host — a bare path, a template, something that is not a URL at all.
     */
    public static function of(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = trim(strtolower($host), '[].');

        return $host === '' ? null : $host;
    }

    /**
     * A host spelled as an IPv4 address, as the 32-bit number it means — in every spelling the C
     * resolver accepts, because `127.1`, `2130706433`, `0x7f.1` and `0177.0.0.1` all reach the same
     * machine and a document naming one of those is as unreachable as one naming `127.0.0.1`. Null
     * when the host is a name.
     */
    public static function ipv4(string $host): ?int
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

    /** The 16 bytes a host spells as an IPv6 address, or null when it spells something else. */
    public static function ipv6(string $host): ?string
    {
        $packed = inet_pton($host);

        return is_string($packed) && strlen($packed) === 16 ? $packed : null;
    }

    /**
     * The IPv4 address an IPv6 one carries in its low 32 bits when it is the `::ffff:0:0/96` mapped
     * spelling of it, else null. A mapped address is judged as the IPv4 address it is.
     */
    public static function mappedIpv4(string $packed): ?int
    {
        if (! str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return null;
        }

        $mapped = unpack('N', substr($packed, 12));

        return is_array($mapped) && is_int($mapped[1] ?? null) ? $mapped[1] : null;
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
}
