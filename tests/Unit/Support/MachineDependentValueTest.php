<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Config\DerivedServers;
use Docuccino\Laravel\Support\MachineDependentValue;
use Illuminate\Config\Repository;

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

/*
 * A published URL carries no credentials — the rule stated from the contract rather than from the
 * code. A document is a committed artifact handed to people who do not have the application's
 * secrets, and every URL in it is an address a client dials: `https://api.acme.com/oauth/token` is
 * the endpoint, and the `svc:s3cr3t@` in front of it is no part of which endpoint that is. So the
 * publisher widens to the address alone rather than refusing a URL that is otherwise correct — OAS
 * requires a `tokenUrl` on every flow object, and a local preview has to keep working.
 */
it('publishes a URL without the credentials it arrived with', function (string $url, string $expected): void {
    expect(MachineDependentValue::withoutCredentials($url))->toBe($expected);
})->with([
    'a user and a password' => ['https://svc:s3cr3t@api.acme.com/oauth', 'https://api.acme.com/oauth'],
    'a user alone' => ['https://svc@api.acme.com/oauth', 'https://api.acme.com/oauth'],
    'an empty user with a password' => ['https://:s3cr3t@api.acme.com', 'https://api.acme.com'],
    // `@` with nothing in front of it is still userinfo, and still not an address.
    'an empty userinfo' => ['https://@api.acme.com', 'https://api.acme.com'],
    // A password holding the delimiter: the strip has to take the WHOLE userinfo, not up to the first
    // `@`, or the published URL would be `ss@api.acme.com` — a different host, confidently wrong.
    'a password holding an at sign' => ['https://svc:p@ss@api.acme.com', 'https://api.acme.com'],
    'a port after the credentials' => ['http://svc:s3cr3t@api.acme.com:8443/v1', 'http://api.acme.com:8443/v1'],
]);

it('leaves a URL carrying no credentials byte-identical', function (string $url): void {
    expect(MachineDependentValue::withoutCredentials($url))->toBe($url);
})->with([
    'a plain host' => ['https://api.acme.com'],
    'a base path' => ['https://api.acme.com/v1'],
    // An `@` in the PATH is not userinfo, and a strip that read it as one would cut the path in half.
    'an at sign in the path' => ['https://api.acme.com/users/a@b'],
    'a query string' => ['https://api.acme.com?tenant=acme'],
    // Degrade honestly: a value parse_url refuses tells us nothing, so nothing is changed about it.
    'a URL parse_url refuses' => ['https://'],
    'a value that is not a URL at all' => ['laravel_session'],
    'an empty string' => [''],
]);

/**
 * The guard, executed rather than asserted. `forCredentials()` decides whether to report and
 * `withoutCredentials()` decides what to publish; a guard that recognised fewer spellings than the
 * strip it announces would publish a corrected URL and say nothing, or report one it did not touch.
 * So the two are held to each other over every row either test above uses.
 */
it('reports exactly the URLs it changes', function (string $url): void {
    $report = MachineDependentValue::forCredentials('The scheme', $url, "the application's 'app.url'");
    $changed = MachineDependentValue::withoutCredentials($url) !== $url;

    expect($report !== null)->toBe($changed);
})->with([
    'a user and a password' => ['https://svc:s3cr3t@api.acme.com/oauth'],
    'a user alone' => ['https://svc@api.acme.com/oauth'],
    'an empty user with a password' => ['https://:s3cr3t@api.acme.com'],
    'an empty userinfo' => ['https://@api.acme.com'],
    'a password holding an at sign' => ['https://svc:p@ss@api.acme.com'],
    'a port after the credentials' => ['http://svc:s3cr3t@api.acme.com:8443/v1'],
    'a plain host' => ['https://api.acme.com'],
    'a base path' => ['https://api.acme.com/v1'],
    'an at sign in the path' => ['https://api.acme.com/users/a@b'],
    'a query string' => ['https://api.acme.com?tenant=acme'],
    'a URL parse_url refuses' => ['https://'],
    'a value that is not a URL at all' => ['laravel_session'],
    'an empty string' => [''],
]);

it('names where to go, what is published now, and never the secret', function (): void {
    $diagnostic = MachineDependentValue::forCredentials(
        "The Passport scheme's OAuth2 flow URLs",
        'https://svc:s3cr3t@api.acme.com',
        "the application's 'app.url'",
        'GET api/x',
    );

    expect($diagnostic)->not->toBeNull()
        ->and($diagnostic->severity)->toBe(Severity::Warning)
        ->and($diagnostic->code)->toBe('config.url-credentials-removed')
        ->and($diagnostic->routeSignature)->toBe('GET api/x')
        ->and($diagnostic->message)->toContain("the application's 'app.url'")
        ->and($diagnostic->message)->toContain("'https://api.acme.com'")
        ->and($diagnostic->message)->not->toContain('s3cr3t')
        ->and($diagnostic->message)->not->toContain('svc')
        ->and($diagnostic->help)->not->toBeNull()
        ->and($diagnostic->help)->toContain("the application's 'app.url'")
        ->and($diagnostic->help)->not->toContain('s3cr3t')
        ->and($diagnostic->help)->not->toContain('svc');
});

/**
 * Whether a URL carries credentials, decided from the URL's own grammar and not by asking the product.
 * RFC 3986 puts the userinfo inside the authority, in front of the host and ending at an `@` — so the
 * authority is everything after `://` and before the first `/`, `?` or `#`, and a URL carries
 * credentials exactly when that span holds an `@`.
 */
function urlAuthorityCarriesCredentials(string $url): bool
{
    $scheme = strpos($url, '://');
    if ($scheme === false) {
        return false;
    }

    $authority = substr($url, $scheme + 3);

    return str_contains(substr($authority, 0, strcspn($authority, '/?#')), '@');
}

/**
 * One fact, three readers. The derived root server refuses a credential-bearing `app.url`, the flow
 * URLs and the host-bound operation server publish one stripped, and each of those hangs off the same
 * answer to "does this URL carry credentials" — so a spelling one of them missed is a leak on one side
 * or a silently dropped server on the other. The expectation is stated from RFC 3986 rather than read
 * back off the product: a guard that asks the code for its own rule agrees with whatever the code
 * does, which is precisely what makes three readers sharing one reader worth checking at all.
 */
it('gives every reader of a URL the same answer about its credentials', function (string $url): void {
    $expected = urlAuthorityCarriesCredentials($url);

    expect(MachineDependentValue::carriesCredentials($url))->toBe($expected)
        ->and(MachineDependentValue::withoutCredentials($url) !== $url)->toBe($expected)
        ->and(MachineDependentValue::forCredentials('The scheme', $url, "the application's 'app.url'") !== null)->toBe($expected)
        // Every row below is publishable on every OTHER axis the derived server judges — a routable
        // public host, http(s), no query, no fragment — so the credential answer is the only thing
        // deciding it, and a row that agreed for some other reason would prove nothing.
        ->and(DerivedServers::for(new Repository(['app' => ['url' => $url]])) === [])->toBe($expected);
})->with([
    'a user and a password' => ['https://svc:s3cr3t@api.acme.com'],
    'a user alone' => ['https://svc@api.acme.com'],
    'an empty user with a password' => ['https://:s3cr3t@api.acme.com'],
    'an empty userinfo' => ['https://@api.acme.com'],
    'a password holding an at sign' => ['https://svc:p@ss@api.acme.com'],
    'credentials before a port' => ['https://svc:s3cr3t@api.acme.com:8443'],
    'a plain host' => ['https://api.acme.com'],
    'a base path' => ['https://api.acme.com/v1'],
    'an at sign in the path' => ['https://api.acme.com/users/a@b'],
    'an at sign in a path under a port' => ['https://api.acme.com:8443/users/a@b'],
]);
