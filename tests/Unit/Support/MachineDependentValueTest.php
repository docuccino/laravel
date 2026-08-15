<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Support\MachineDependentValue;

/**
 * The shared rule behind the machine-dependent-value reports. The host tables are lookup tables, so
 * they are driven over every entry they hold — and the address half over every SPELLING of an address
 * a resolver accepts, since `127.1` and `2130706433` reach the same machine as `127.0.0.1` and a
 * document naming one of them is exactly as unreachable.
 *
 * But the row that carries the contract is the NEGATIVE one: a document built against a real public API
 * must stay silent, or the warning is noise and `--fail-on=warning` becomes something teams switch off.
 */
it('reads a loopback or local-development host as machine-dependent', function (string $url): void {
    expect(MachineDependentValue::isLocalUrl($url))->toBeTrue();
})->with([
    // The named half of the table.
    'localhost' => ['http://localhost'],

    // Every entry of the reserved-suffix table.
    '.localhost' => ['http://acme.localhost'],
    '.test' => ['https://acme.test/api'],
    '.local' => ['https://acme.local'],
    '.example' => ['https://api.acme.example'],
    '.localdomain' => ['http://localhost.localdomain/oauth'],

    // The dotted-quad spellings.
    '127.0.0.1' => ['http://127.0.0.1:8000/oauth'],
    'another address in 127.0.0.0/8' => ['http://127.0.0.2/oauth'],
    'the top of 127.0.0.0/8' => ['http://127.255.255.254'],
    '0.0.0.0' => ['http://0.0.0.0:80'],

    // …and the spellings a dotted-quad check waves straight through.
    'a two-part address' => ['http://127.1'],
    'a three-part address' => ['http://127.0.1/oauth'],
    'a bare integer address' => ['http://2130706433/oauth'],
    'a hex address' => ['http://0x7f.1'],
    'a fully hex address' => ['http://0x7f000001'],
    'an octal address' => ['http://0177.0.0.1'],
    'the unspecified address as an integer' => ['http://0'],

    // IPv6, in the bracket syntax a URL carries it in.
    '::1 in IPv6 brackets' => ['http://[::1]:8000/oauth'],
    'the unspecified IPv6 address' => ['http://[::]'],
    'an IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/oauth'],
    'an IPv4-mapped loopback in hex groups' => ['http://[::ffff:7f00:1]'],

    // Spellings of the same names a case-sensitive or dot-blind check would wave through.
    'an upper-case host' => ['http://LOCALHOST/oauth'],
    'a fully-qualified trailing dot' => ['https://acme.test./api'],
    'a deeper subdomain of a reserved suffix' => ['https://auth.eu.acme.test'],
    'credentials in front of a local host' => ['https://user:pw@localhost/oauth'],
]);

it('reads anything else as a fine thing to publish', function (string $url): void {
    expect(MachineDependentValue::isLocalUrl($url))->toBeFalse();
})->with([
    // THE rows that matter: a real deployment must not be reported.
    'a public https URL' => ['https://api.acme.com'],
    'a public URL on a port' => ['https://auth.acme.co.uk:8443/oauth'],
    'a public IPv4 address' => ['https://8.8.8.8/oauth'],
    'a public IPv6 address' => ['https://[2001:4860:4860::8888]/oauth'],
    // Deliberate: a LAN address is reachable by more than the build machine, and reporting one would
    // warn every app documented from inside a private network.
    'a private LAN address' => ['https://192.168.1.10/oauth'],

    // Names that merely CONTAIN a reserved word or an address, which a substring check would claim.
    'a host containing "localhost"' => ['https://localhost.acme.com'],
    'a host containing "test"' => ['https://test.acme.com'],
    'a public host ending in the word test' => ['https://acme-test.com'],
    'a public host whose label is example' => ['https://example.com'],
    'a host whose first label is the loopback address' => ['https://127.0.0.1.acme.com'],
    'a host whose first label is the integer loopback' => ['https://2130706433.acme.com'],
    'a host whose last label merely starts with localdomain' => ['https://acme.localdomainhost'],

    // Not addresses at all, however much they look like one.
    'a dotted quad with an out-of-range byte' => ['https://256.0.0.1'],
    'five dotted parts' => ['https://1.2.3.4.5'],
    'a part with a non-octal digit under a leading zero' => ['https://0189.0.0.1'],

    // Nothing to go on rather than evidence of trouble: guessing here would report a real API.
    'a relative path' => ['/oauth'],
    'an empty string' => [''],
    'a value that is not a URL at all' => ['laravel_session'],
]);

it('reports an unpinned local URL as a warning naming the value, the key and the pin', function (): void {
    $diagnostic = MachineDependentValue::forUrl(
        'The Passport scheme', 'http://localhost', 'app.url', 'integrations.passport.url', 'GET api/x',
    );

    expect($diagnostic)->not->toBeNull()
        ->and($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->code)->toBe('config.machine-dependent-value')
        ->and($diagnostic->routeSignature)->toBe('GET api/x')
        ->and($diagnostic->message)->toContain('http://localhost')
        ->and($diagnostic->message)->toContain('app.url')
        ->and($diagnostic->help)->not->toBeNull()
        ->and($diagnostic->help)->toContain('integrations.passport.url');
});

it('reports nothing for a URL a consumer can actually call', function (): void {
    expect(MachineDependentValue::forUrl(
        'The Passport scheme', 'https://auth.acme.com', 'app.url', 'integrations.passport.url',
    ))->toBeNull();
});

/**
 * A diagnostic message goes into CI logs, so the one value it must not carry verbatim is the one an
 * `APP_URL` carrying credentials puts in front of the host.
 */
it('blanks credentials out of the URL it quotes', function (string $url, string $expected): void {
    $diagnostic = MachineDependentValue::forUrl(
        'The Passport scheme', $url, 'app.url', 'integrations.passport.url',
    );

    expect($diagnostic)->not->toBeNull()
        ->and($diagnostic->message)->toContain($expected)
        ->and($diagnostic->message)->not->toContain('sup3rs3cret');
})->with([
    'a user and a password' => ['https://user:sup3rs3cret@localhost/oauth', 'https://***@localhost/oauth'],
    'a user alone' => ['https://user@localhost/oauth', 'https://***@localhost/oauth'],
    'an empty user with a password' => ['https://:sup3rs3cret@localhost/oauth', 'https://***@localhost/oauth'],
]);

it('leaves a URL with no credentials exactly as it found it', function (): void {
    $diagnostic = MachineDependentValue::forUrl(
        'The Passport scheme', 'http://localhost:8000/oauth', 'app.url', 'integrations.passport.url',
    );

    expect($diagnostic?->message)->toContain("'http://localhost:8000/oauth'");
});

it('reports a host the route bound itself to, with no config key to name', function (): void {
    $diagnostic = MachineDependentValue::forHost(
        'The operation server URL', 'https://admin.acme.test/v1', 'GET admin.acme.test/api/orders',
    );

    expect($diagnostic)->not->toBeNull()
        ->and($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->code)->toBe('config.machine-dependent-value')
        ->and($diagnostic->routeSignature)->toBe('GET admin.acme.test/api/orders')
        ->and($diagnostic->message)->toContain('https://admin.acme.test/v1')
        ->and($diagnostic->help)->toContain('overlay');
});

it('reports nothing for a host a client can actually reach', function (): void {
    expect(MachineDependentValue::forHost(
        'The operation server URL', 'https://admin.acme.com/v1', 'GET admin.acme.com/api/orders',
    ))->toBeNull();
});

it('reports an unpinned opaque value on where it came from, since it has no host to judge', function (): void {
    $diagnostic = MachineDependentValue::forValue(
        'The Sanctum stateful scheme', 'acme_crm_session', 'session.cookie', 'integrations.sanctum.cookie',
    );

    expect($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->code)->toBe('config.machine-dependent-value')
        ->and($diagnostic->routeSignature)->toBeNull()
        ->and($diagnostic->message)->toContain('acme_crm_session')
        ->and($diagnostic->message)->toContain('session.cookie')
        ->and($diagnostic->message)->toContain('environment')
        ->and($diagnostic->help)->toContain('integrations.sanctum.cookie');
});

it('reports a value no config key supplied as the fallback default it is', function (): void {
    $diagnostic = MachineDependentValue::forDefault(
        'The Sanctum stateful scheme', 'laravel_session', 'session.cookie', 'integrations.sanctum.cookie',
    );

    expect($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->code)->toBe('config.machine-dependent-value')
        ->and($diagnostic->message)->toContain('laravel_session')
        ->and($diagnostic->message)->toContain('session.cookie')
        ->and($diagnostic->message)->toContain('fallback default')
        ->and($diagnostic->help)->toContain('integrations.sanctum.cookie');
});
