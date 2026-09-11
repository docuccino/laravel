<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Laravel\Support\HostAddress;
use Docuccino\Laravel\Support\MachineDependentValue;

/**
 * Whether a host is one the reader of an exported document could reach, which is the question
 * PUBLISHING a server has to ask.
 *
 * It is not the question {@see MachineDependentValue::isLocalUrl()} answers, and the difference is
 * which way being wrong costs. That rule decides whether to WARN, so it stops at hosts that name the
 * build machine and calls a LAN address fine on purpose: warning about one would fire on every
 * application documented from inside a private network, where nothing is wrong. This one decides
 * what every generated client is aimed at, and the reader of an exported document is by construction
 * outside the network it was built in — so `10.0.0.5` is reachable by more than the build machine and
 * by none of them.
 *
 * Determinism rules out the lookup that would settle it, so the answer is a function of the
 * configured string alone: an address is decided by the block it falls in, which is published fact,
 * and a name can only be DISPROVED — by a suffix no root zone delegates, or by having no dots, which
 * leaves it to whatever search domain the build machine carries. A name that is neither is published,
 * because the alternative is bytes that depend on what the build network could resolve.
 *
 * @internal
 */
final class ReachableHost
{
    /**
     * Every IPv4 block the address registry marks as reachable by less than the whole internet, as
     * `[network, prefix length]`. The blocks outside `1.0.0.0`–`223.255.255.255` — `0.0.0.0/8`,
     * multicast, and everything reserved above `240.0.0.0` — are refused by the bound instead.
     *
     * @var list<array{int, int}>
     */
    private const UNREACHABLE_V4 = [
        [0x0A000000, 8],   // 10.0.0.0/8 — private use (RFC 1918)
        [0x64400000, 10],  // 100.64.0.0/10 — shared address space, behind a carrier NAT (RFC 6598)
        [0x7F000000, 8],   // 127.0.0.0/8 — loopback
        [0xA9FE0000, 16],  // 169.254.0.0/16 — link-local, and with it the 169.254.169.254 metadata address
        [0xAC100000, 12],  // 172.16.0.0/12 — private use (RFC 1918)
        [0xC0000000, 24],  // 192.0.0.0/24 — IETF protocol assignments
        [0xC0000200, 24],  // 192.0.2.0/24 — documentation, TEST-NET-1 (RFC 5737)
        [0xC0A80000, 16],  // 192.168.0.0/16 — private use (RFC 1918)
        [0xC6120000, 15],  // 198.18.0.0/15 — benchmarking (RFC 2544)
        [0xC6336400, 24],  // 198.51.100.0/24 — documentation, TEST-NET-2 (RFC 5737)
        [0xCB007100, 24],  // 203.0.113.0/24 — documentation, TEST-NET-3 (RFC 5737)
    ];

    /**
     * The blocks carved OUT of IPv6 global unicast, as leading hex digits of the packed address.
     * Everything else that is not reachable — the unspecified address, `::1`, unique-local
     * `fc00::/7`, link-local `fe80::/10`, multicast `ff00::/8` — is outside `2000::/3` and refused
     * by that test instead.
     *
     * @var list<string>
     */
    private const UNREACHABLE_V6 = [
        '20010db8',  // 2001:db8::/32 — documentation (RFC 3849)
        '3fff0',     // 3fff::/20 — documentation (RFC 9637)
    ];

    /**
     * Suffixes under which a name cannot resolve for anyone outside the network that answers for it,
     * because no root zone will ever delegate them. Reserved by the IETF, or — the last three —
     * withheld by ICANN permanently as too collision-prone to delegate.
     *
     * @var list<string>
     */
    private const UNREACHABLE_SUFFIXES = [
        '.internal',   // reserved for private-use applications; `host.docker.internal` is one
        '.invalid',    // RFC 6761
        '.alt',        // RFC 9476 — resolved by something that is not the DNS
        '.home.arpa',  // RFC 8375 — a home network's own zone
        '.corp',
        '.home',
        '.mail',
    ];

    /** Whether `$url`'s host is one a reader of the document could reach. */
    public static function isPublishableUrl(string $url): bool
    {
        $host = HostAddress::of($url);
        if ($host === null) {
            return false;
        }

        $address = HostAddress::ipv4($host);
        if ($address !== null) {
            return self::isGlobalV4($address);
        }

        $packed = HostAddress::ipv6($host);
        if ($packed !== null) {
            return self::isGlobalV6($packed);
        }

        // A single label is not a name the internet has: it resolves through the build machine's own
        // search domain, and nowhere else. Dotless delegations do not exist in the root zone.
        if (! str_contains($host, '.')) {
            return false;
        }

        foreach (self::UNREACHABLE_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return true;
    }

    private static function isGlobalV4(int $address): bool
    {
        $leading = $address >> 24;
        if ($leading < 1 || $leading > 223) {
            return false;
        }

        foreach (self::UNREACHABLE_V4 as [$network, $bits]) {
            if (($address & (-1 << (32 - $bits)) & 0xFFFFFFFF) === $network) {
                return false;
            }
        }

        return true;
    }

    private static function isGlobalV6(string $packed): bool
    {
        // A mapped address is the IPv4 address it carries, judged as one — `::ffff:10.0.0.5` reaches
        // exactly what `10.0.0.5` reaches.
        $mapped = HostAddress::mappedIpv4($packed);
        if ($mapped !== null) {
            return self::isGlobalV4($mapped);
        }

        $hex = bin2hex($packed);
        if ($hex[0] !== '2' && $hex[0] !== '3') {
            return false;
        }

        foreach (self::UNREACHABLE_V6 as $prefix) {
            if (str_starts_with($hex, $prefix)) {
                return false;
            }
        }

        return true;
    }
}
