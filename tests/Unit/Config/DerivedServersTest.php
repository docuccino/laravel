<?php

declare(strict_types=1);

use Docuccino\Laravel\Config\DerivedServers;
use Docuccino\Laravel\Support\MachineDependentValue;
use Illuminate\Config\Repository;

/**
 * The rule, stated from the contract rather than from the code: a document that declares no servers
 * may publish `app.url` only when that URL is something a consumer of the document could actually
 * prepend to a path — a scheme an HTTP client speaks, a host that reader could reach, and no
 * credentials or query string, neither of which an OAS Server Object admits. Anything else publishes
 * NOTHING, because OpenAPI reads an absent `servers` as the origin the document is served from, which
 * is a working answer, where a wrong URL is a runtime failure.
 *
 * Who that reader is settles the host half. A document is exported and handed to people outside the
 * network it was built in, so the question is not whether a host is more than the build machine —
 * `10.0.0.5` is, and reaches none of them — but whether it is reachable from outside at all. An
 * address answers that outright, because which blocks the internet routes is published fact. A name
 * can only be disproved, by a suffix no root zone delegates or by having no dots at all; nothing is
 * ever resolved, because a document whose bytes depend on what the build network could look up is
 * not deterministic.
 *
 * @return list<array{url: string}>
 */
function derivedFor(?string $url): array
{
    return DerivedServers::for(new Repository(['app' => ['url' => $url]]));
}

it('publishes a reachable application URL as the document server', function (string $url, string $expected): void {
    expect(derivedFor($url))->toBe([['url' => $expected]]);
})->with([
    'a plain https host' => ['https://api.acme.com', 'https://api.acme.com'],
    'plain http' => ['http://api.acme.com', 'http://api.acme.com'],
    // A server url is joined to a path that already starts with `/`, so a trailing slash would
    // publish `https://api.acme.com//orders`.
    'a trailing slash' => ['https://api.acme.com/', 'https://api.acme.com'],
    'a base path' => ['https://acme.com/api/v2', 'https://acme.com/api/v2'],
    'a base path with a trailing slash' => ['https://acme.com/api/', 'https://acme.com/api'],
    'an explicit port' => ['https://api.acme.com:8443', 'https://api.acme.com:8443'],
    'a mixed-case scheme' => ['HTTPS://api.acme.com', 'https://api.acme.com'],
    // A public host that merely CONTAINS a reserved label is not under it.
    'a host containing a reserved label' => ['https://test.acme.com', 'https://test.acme.com'],
    'a host whose label is internal' => ['https://internal.acme.com', 'https://internal.acme.com'],
    'a host ending in the word internal' => ['https://acme-internal.com', 'https://acme-internal.com'],
    // An address a consumer can route to is as good an answer as a name, and the rows below that
    // refuse a private one are worth nothing without these beside them.
    'a routable IPv4 address' => ['https://8.8.8.8', 'https://8.8.8.8'],
    'a routable IPv6 address' => ['https://[2001:4860:4860::8888]', 'https://[2001:4860:4860::8888]'],
]);

it('publishes nothing where the application URL proves nothing', function (?string $url): void {
    expect(derivedFor($url))->toBe([]);
})->with([
    'unset' => [null],
    'empty' => [''],
    // Laravel's own shipped default. Every application that never set APP_URL hands us this.
    'the framework default' => ['http://localhost'],
    'loopback by address' => ['http://127.0.0.1:8000'],
    'the ipv6 loopback' => ['http://[::1]'],
    'a .test development host' => ['http://acme.test'],
    'a .local development host' => ['http://acme.local'],
    // No scheme means no absolute URL: OAS would read it as a relative server path, which is not
    // what the value says.
    'no scheme' => ['api.acme.com'],
    'a scheme no HTTP client speaks' => ['ftp://api.acme.com'],
    'a relative path' => ['/api'],
    // Credentials in a committed artifact, and neither of these belongs in a Server Object.
    'embedded credentials' => ['https://user:secret@api.acme.com'],
    'a query string' => ['https://api.acme.com?tenant=acme'],
    'a fragment' => ['https://api.acme.com#api'],
]);

/**
 * A document reaches its reader by being exported, and no reader of an exported document is inside
 * the network that built it. So every one of these is a host the document would be lying about: a
 * private address, an address the internet does not route to a host at all, a name under a suffix
 * that resolves only where it is served, or an unqualified name that resolves through whatever
 * search domain the build machine happens to carry. The dataset walks every block and every suffix
 * the rule holds, because a table proves only the rows it lists.
 */
it('publishes nothing where the host answers only inside the network the build ran in', function (string $url): void {
    expect(derivedFor($url))->toBe([]);
})->with([
    // Every IPv4 block, by the reason it is not reachable.
    'private use 10.0.0.0/8' => ['http://10.0.0.5/api'],
    'shared address space behind a carrier NAT' => ['http://100.64.0.1'],
    'loopback' => ['http://127.0.0.1:8000'],
    'link-local' => ['http://169.254.10.1'],
    // The address every cloud instance answers on, and the one a document must never send a client at.
    'the instance metadata address' => ['http://169.254.169.254'],
    'private use 172.16.0.0/12' => ['https://172.16.4.9/api'],
    'IETF protocol assignments' => ['http://192.0.0.8'],
    'documentation TEST-NET-1' => ['http://192.0.2.10'],
    'private use 192.168.0.0/16' => ['http://192.168.1.50:8000'],
    'benchmarking' => ['http://198.18.0.1'],
    'documentation TEST-NET-2' => ['http://198.51.100.10'],
    'documentation TEST-NET-3' => ['http://203.0.113.10'],
    'this network' => ['http://0.1.2.3'],
    'multicast' => ['http://239.255.255.250'],
    'the reserved space above 240' => ['http://240.0.0.1'],
    'the broadcast address' => ['http://255.255.255.255'],

    // IPv6: everything outside global unicast, and the two blocks carved out of it.
    'the IPv6 loopback' => ['http://[::1]'],
    'the unspecified IPv6 address' => ['http://[::]'],
    'a unique-local address' => ['https://[fd00::1]'],
    'the bottom of the unique-local block' => ['https://[fc00::1]'],
    'an IPv6 link-local address' => ['http://[fe80::1]'],
    'IPv6 multicast' => ['http://[ff02::1]'],
    'below global unicast' => ['http://[1fff::1]'],
    'above global unicast' => ['http://[4000::1]'],
    'IPv6 documentation' => ['http://[2001:db8::1]'],
    'the second IPv6 documentation block' => ['http://[3fff:0:1::1]'],

    // Names, by the reason no root zone answers for them.
    'the private-use TLD' => ['http://host.docker.internal:8080'],
    'a name reserved as invalid' => ['http://acme.invalid'],
    'a name resolved outside the DNS' => ['http://acme.alt'],
    'a home network zone' => ['http://router.home.arpa'],
    'a name under a TLD withheld from delegation' => ['https://api.internal.corp'],
    'another withheld TLD' => ['http://api.home'],
    'a third withheld TLD' => ['http://api.mail'],
    // A CI runner and a container both hand us one of these, and it names a machine on one network.
    'an unqualified host' => ['http://build-agent-07'],
    'a bare label' => ['http://api'],
    'a host that is nothing but dots' => ['http://.../api'],
]);

/**
 * The other half of every block above: the address one below it, or one past its top, is a host on
 * the public internet and must still be published. A gate that refuses everything passes the rows
 * above and takes a correct `APP_URL` down with it, and a mask one bit too wide swallows a routable
 * neighbour silently.
 */
it('publishes the addresses just outside each block it refuses', function (string $url): void {
    expect(derivedFor($url))->toBe([['url' => $url]]);
})->with([
    'below 10.0.0.0/8' => ['http://9.255.255.255'],
    'above 10.0.0.0/8' => ['http://11.0.0.1'],
    'below the shared address space' => ['http://100.63.255.255'],
    'above the shared address space' => ['http://100.128.0.1'],
    'below loopback' => ['http://126.255.255.255'],
    'above loopback' => ['http://128.0.0.1'],
    'below link-local' => ['http://169.253.255.255'],
    'above link-local' => ['http://169.255.0.1'],
    'below 172.16.0.0/12' => ['http://172.15.255.255'],
    'above 172.16.0.0/12' => ['http://172.32.0.1'],
    'above the protocol assignments' => ['http://192.0.1.1'],
    'above TEST-NET-1' => ['http://192.0.3.1'],
    'below 192.168.0.0/16' => ['http://192.167.255.255'],
    'above 192.168.0.0/16' => ['http://192.169.0.1'],
    'below the benchmarking block' => ['http://198.17.255.255'],
    'above the benchmarking block' => ['http://198.20.0.1'],
    'above TEST-NET-2' => ['http://198.51.101.1'],
    'above TEST-NET-3' => ['http://203.0.114.1'],
    'the first routable address' => ['http://1.0.0.1'],
    'the last address below multicast' => ['http://223.255.255.254'],
    'the bottom of IPv6 global unicast' => ['http://[2000::1]'],
    'beside the IPv6 documentation block' => ['http://[2001:db9::1]'],
    'above the second IPv6 documentation block' => ['http://[3fff:1000::1]'],
]);

/**
 * A guard that reads fewer spellings than the resolver does is a hole: `0xc0a80132` and `192.168.306`
 * both reach the machine `192.168.1.50` reaches, and an `APP_URL` is written by hand.
 */
it('reads a private address in every spelling a resolver accepts', function (string $url): void {
    expect(derivedFor($url))->toBe([]);
})->with([
    'a hex address' => ['http://0xc0a80132'],
    'a bare integer address' => ['http://3232235826'],
    'an octal address' => ['http://0300.0250.1.50'],
    'a three-part address' => ['http://192.168.306'],
    'a two-part address' => ['http://10.1'],
    'a fully-qualified trailing dot' => ['http://192.168.1.50./api'],
    'an upper-case host' => ['HTTP://HOST.DOCKER.INTERNAL'],
    'an IPv4-mapped private address' => ['http://[::ffff:10.0.0.5]'],
    'an IPv4-mapped private address in hex groups' => ['http://[::ffff:0a00:0005]'],
]);

/** The mapped spelling of a routable address is still routable, so it is not refused for being mapped. */
it('publishes an IPv4-mapped address that is routable', function (): void {
    expect(derivedFor('http://[::ffff:8.8.8.8]'))->toBe([['url' => 'http://[::ffff:8.8.8.8]']]);
});

/**
 * The two host judgements in this build are not the same judgement, and the implication runs one way
 * only. The published-value rule decides whether to WARN, where a false alarm costs an application a
 * warning it can do nothing about, so it calls a LAN address fine. Publication decides what every
 * generated client is aimed at, where the same LAN address is a host the reader cannot resolve. So
 * everything that rule reports must be omitted here — never the reverse.
 */
it('omits everything the published-value rule calls machine-dependent', function (string $url): void {
    expect(MachineDependentValue::isLocalUrl($url))->toBeTrue()
        ->and(derivedFor($url))->toBe([]);
})->with([
    'http://localhost',
    'http://127.0.0.1',
    'http://acme.test',
    'http://acme.localdomain',
    'https://acme.example',
    'http://[::1]',
]);

/**
 * And the implication is STRICT, which is the row that bites: each of these is a host the
 * published-value rule deliberately declines to report — reporting one would warn every application
 * documented from inside a private network — and every one of them is a host no reader of the
 * exported document can reach. If publication ever asks only that rule's question again, these fail.
 */
it('omits hosts the published-value rule does not report', function (string $url): void {
    expect(MachineDependentValue::isLocalUrl($url))->toBeFalse()
        ->and(derivedFor($url))->toBe([]);
})->with([
    'a LAN address' => ['http://192.168.1.50:8000'],
    'a private address in 10.0.0.0/8' => ['http://10.0.0.5/api'],
    'the instance metadata address' => ['http://169.254.169.254'],
    'a unique-local IPv6 address' => ['https://[fd00::1]'],
    'a host under a withheld TLD' => ['https://api.internal.corp'],
    'the container host alias' => ['http://host.docker.internal:8080'],
    'an unqualified build-agent name' => ['http://build-agent-07'],
]);

/** The control for both rows above: neither rule refuses a host the whole internet can reach. */
it('publishes a host neither rule refuses', function (): void {
    expect(MachineDependentValue::isLocalUrl('https://api.acme.com'))->toBeFalse()
        ->and(derivedFor('https://api.acme.com'))->toBe([['url' => 'https://api.acme.com']]);
});
