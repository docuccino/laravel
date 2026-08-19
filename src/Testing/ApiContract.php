<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\ContractMessages;
use Docuccino\Core\Contract\Coverage\CoverageReport;
use Docuccino\Core\Contract\Exchange;
use Docuccino\Laravel\Testing\Contracts\ContractObserver;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The suite-wide entry point for contract testing: which artifact the assertions read, who observes
 * each exchange, and what the run covered.
 *
 * Nothing here is wired into the service provider, and nothing in the provider's boot path reaches it.
 * A production install never loads this class, its `illuminate/testing` and PHPUnit imports, or the
 * `TestResponse` macros — {@see registerMacros()} is called from a test bootstrap or not at all.
 *
 * State is static because a test suite is one run against one contract, and threading a repository
 * through every test case buys nothing. {@see reset()} puts it back.
 */
final class ApiContract
{
    private static ?string $path = null;

    private static string $documentKey = 'default';

    private static ?ContractIndex $index = null;

    private static ?CoverageRecorder $coverage = null;

    /** @var list<ContractObserver> */
    private static array $observers = [];

    /** Read the artifact at this path (relative paths resolve against the application base path). */
    public static function using(string $path): void
    {
        self::$path = $path;
        self::$index = null;
    }

    /** Assert against a document other than `default`. */
    public static function forDocument(string $key): void
    {
        self::$documentKey = $key;
        self::$index = null;
    }

    public static function documentKey(): string
    {
        return self::$documentKey;
    }

    public static function observe(ContractObserver $observer): void
    {
        self::$observers[] = $observer;
    }

    /**
     * Record what this run's responses look like, as the examples the document publishes.
     *
     * One line in a test bootstrap and the suite starts writing a committed file per operation; the
     * build reads those files, and goes on executing nothing. Pass a directory to override
     * `examples.recordings`. See {@see ExampleRecorder} for what gets chosen and what gets redacted,
     * and `recordAs:` on the response assertions for publishing several named scenarios at once.
     */
    public static function record(?string $directory = null): ExampleRecorder
    {
        $recorder = new ExampleRecorder($directory);

        self::observe($recorder);

        return $recorder;
    }

    /** The assertions as an object, for a test that cannot use the trait. */
    public static function assertions(): ContractAssertions
    {
        return new ContractAssertions;
    }

    public static function coverage(): CoverageRecorder
    {
        return self::$coverage ??= new CoverageRecorder;
    }

    public static function report(): CoverageReport
    {
        return CoverageReport::of(self::index(), self::coverage()->exercised());
    }

    public static function build(): ContractBuild
    {
        $build = new ContractBuild(self::$documentKey);

        if (! $build->exists()) {
            throw UnreadableContract::unknownDocument(self::$documentKey);
        }

        return $build;
    }

    public static function artifactPath(): string
    {
        $build = self::build();

        return ArtifactLocator::locate($build->config(), base_path(), self::$path);
    }

    /** The indexed artifact, read once per process — a suite asserts against it hundreds of times. */
    public static function index(): ContractIndex
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $path = self::artifactPath();
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw UnreadableContract::notFound($path, self::$documentKey);
        }

        return self::$index = ContractBuild::indexOf($contents, $path);
    }

    /** Forget the artifact, the observers and everything the run recorded. */
    public static function reset(): void
    {
        self::$path = null;
        self::$documentKey = 'default';
        self::$index = null;
        self::$coverage = null;
        self::$observers = [];
    }

    /**
     * Match the exchange to its documented operation, check the halves asked for, tell the observers,
     * and fail the test when the contract and the exchange disagree — in that order, so an observer
     * sees a failing exchange as well as a passing one.
     *
     * `$recordAs` names the scenario for {@see ExampleRecorder}, and is ignored by a suite that is not
     * recording.
     *
     * @param  TestResponse<Response>  $response
     */
    public static function assertExchange(TestResponse $response, bool $checkRequest, bool $checkResponse, ?string $recordAs = null): void
    {
        $index = self::index();
        $request = self::requestFor($response);
        $exchange = self::exchangeFor($request, $response);

        $result = (new ContractChecker($index))->check($exchange, $checkRequest, $checkResponse);
        $operation = $result->operation;

        if ($operation === null) {
            Assert::fail(ContractMessages::undocumented(
                $exchange,
                $index,
                'The artifact predates this route — rebuild it: php artisan docuccino:export',
            ));
        }

        self::notify(new ObservedExchange($operation, $exchange, $request, $response, $result, $recordAs));

        $failures = $result->failures();

        if ($failures !== []) {
            Assert::fail(ContractMessages::exchange($operation, $exchange, $result));
        }

        // The exchange matched the contract and violated nothing: register that as the assertion it is,
        // so a test whose only check is this one is not reported as having performed none.
        Assert::assertThat($failures, Assert::isEmpty());
    }

    /**
     * Chainable versions of the assertions on `TestResponse` itself. Call once from your test
     * bootstrap — the package registers nothing on its own, so a production boot loads none of this.
     */
    public static function registerMacros(): void
    {
        TestResponse::macro('assertValidRequest', function (): TestResponse {
            /** @var TestResponse<Response> $this */
            ApiContract::assertExchange($this, true, false);

            return $this;
        });

        TestResponse::macro('assertValidResponse', function (?string $recordAs = null): TestResponse {
            /** @var TestResponse<Response> $this */
            ApiContract::assertExchange($this, false, true, $recordAs);

            return $this;
        });

        TestResponse::macro('assertValidExchange', function (?string $recordAs = null): TestResponse {
            /** @var TestResponse<Response> $this */
            ApiContract::assertExchange($this, true, true, $recordAs);

            return $this;
        });
    }

    /**
     * The Laravel pair reduced to the neutral value core checks.
     *
     * @param  TestResponse<Response>  $response
     */
    public static function exchangeFor(Request $request, TestResponse $response): Exchange
    {
        $base = $response->baseResponse;
        $body = $base->getContent();

        /** @var array<string, mixed> $query */
        $query = $request->query->all();

        return new Exchange(
            method: $request->getMethod(),
            path: $request->getPathInfo(),
            status: $base->getStatusCode(),
            query: $query,
            headers: self::headers($request),
            cookies: self::strings($request->cookies->all()),
            requestBody: $request->getContent(),
            requestContentType: $request->headers->get('Content-Type'),
            responseBody: $body === false ? '' : $body,
            responseContentType: $base->headers->get('Content-Type'),
        );
    }

    private static function notify(ObservedExchange $exchange): void
    {
        self::coverage()->observed($exchange);

        foreach (self::$observers as $observer) {
            $observer->observed($exchange);
        }
    }

    /**
     * The request that produced the response. Laravel hands it to `TestResponse` itself; the bound
     * `request` instance is the fallback for a response built by hand.
     *
     * @param  TestResponse<Response>  $response
     */
    private static function requestFor(TestResponse $response): Request
    {
        if ($response->baseRequest instanceof Request) {
            return $response->baseRequest;
        }

        $container = Container::getInstance();
        $request = $container->bound('request') ? $container->get('request') : null;

        if ($request instanceof Request) {
            return $request;
        }

        throw new RuntimeException(
            'Docuccino cannot tell which request produced this response. Assert on the TestResponse a '.
            'test-suite call returned ($this->getJson(…)), which carries its own request.'
        );
    }

    /**
     * @return array<string, string>
     */
    private static function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $first = $values[0] ?? null;

            if (is_string($first)) {
                $headers[(string) $name] = $first;
            }
        }

        return $headers;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, string>
     */
    private static function strings(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }
}
