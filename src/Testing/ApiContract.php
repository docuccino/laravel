<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\ContractMessages;
use Docuccino\Core\Contract\ContractWebhook;
use Docuccino\Core\Contract\Coverage\CoverageReport;
use Docuccino\Core\Contract\Exchange;
use Docuccino\Core\Support\PlainText;
use Docuccino\Laravel\Support\ArtifactLocator;
use Docuccino\Laravel\Testing\Contracts\ContractObserver;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use JsonException;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * The suite-wide entry point for contract testing: which artifact the assertions read, who observes
 * each exchange, and what this process covered.
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
     * One line in a test bootstrap, and every assertion that NAMES its scenario — `recordAs:` on the
     * response assertions — writes a committed file for its operation; the build reads those files, and
     * goes on executing nothing. Nothing else is recorded: an assertion that names no scenario is
     * checking a response, not choosing what the document shows. Pass a directory to override
     * `examples.recordings`. See {@see ExampleRecorder} for what gets chosen and what gets redacted.
     */
    public static function record(?string $directory = null): ExampleRecorder
    {
        $recorder = new ExampleRecorder($directory);

        self::observe($recorder);

        return $recorder;
    }

    /**
     * Log which operations this process exercises, for `docuccino:coverage` to merge and gate after the
     * run.
     *
     * One line in a test bootstrap, and it reads the same under one process, twenty workers or four
     * shards on four machines: every process writes a file of its own, and the whole-suite question is
     * answered afterwards by something that can see the whole suite. Pass a directory to override
     * `coverage.log`.
     */
    public static function recordCoverage(?string $directory = null): CoverageRecorder
    {
        return self::coverage()->logTo($directory);
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
     * `$recordAs` names the scenario for {@see ExampleRecorder} — and asks for it: nothing is recorded
     * from an exchange that named none. It is ignored by a suite that is not recording.
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

        self::warn(ContractMessages::uncheckedExchange($exchange, $result));

        // The exchange matched the contract and violated nothing: register that as the assertion it is,
        // so a test whose only check is this one is not reported as having performed none.
        Assert::assertThat($failures, Assert::isEmpty());
    }

    /**
     * Resolve the webhook by NAME, reduce the payload to the bytes it would be delivered as, and fail
     * the test when the two disagree — or when the document publishes no body for it to be held to.
     *
     * `$method` is only ever needed for a name the document publishes under more than one; with one, it
     * is the one.
     *
     * A delivery does not go through {@see notify()}: an {@see ObservedExchange} needs an operation and a
     * `TestResponse`, and a delivery has neither. It reaches the coverage recorder by id and WITHOUT a
     * status — a webhook's statuses are what the RECEIVER answers, and nothing a sender's suite does can
     * exercise one — which is the delivery row {@see CoverageReport} counts it as.
     */
    public static function assertWebhook(string $name, mixed $payload, ?string $method = null): void
    {
        $index = self::index();

        if (! $index->supportsWebhooks()) {
            Assert::fail(ContractMessages::webhooksUnsupported(
                $index,
                'Export the document as UIR and point the assertions at it: php artisan docuccino:export',
            ));
        }

        $candidates = $method === null ? $index->webhooksNamed($name) : array_values(array_filter(
            $index->webhooksNamed($name),
            static fn (ContractWebhook $webhook): bool => strcasecmp($webhook->method, $method) === 0,
        ));

        if ($candidates === []) {
            Assert::fail(ContractMessages::undocumentedWebhook(
                $name,
                $method,
                $index,
                'The artifact predates this webhook — rebuild it: php artisan docuccino:export',
            ));
        }

        if (count($candidates) > 1) {
            Assert::fail(ContractMessages::ambiguousWebhook(
                $name,
                $candidates,
                sprintf(
                    "Name the one you send: assertValidWebhook('%s', \$payload, method: '%s').",
                    PlainText::of($name),
                    PlainText::of(strtolower($candidates[0]->method)),
                ),
            ));
        }

        $webhook = $candidates[0];

        // Recorded here, before the check, exactly as notify() is: a delivery that disagrees is still one
        // the suite asserted about.
        if ($webhook->id !== null) {
            self::coverage()->record($webhook->id);
        }

        try {
            $json = WebhookPayload::json($payload);
        } catch (JsonException $exception) {
            Assert::fail(ContractMessages::unreadableDelivery(
                $webhook,
                $exception->getMessage(),
                'Pass the array, the JSON string or the object your application actually delivers.',
            ));
        }

        $outcome = (new ContractChecker($index))->delivery($webhook, $json, WebhookPayload::emptyIsAmbiguous($payload));

        if (! $outcome->ok()) {
            Assert::fail(ContractMessages::delivery($webhook, $outcome));
        }

        self::warn(ContractMessages::uncheckedDelivery($webhook, $outcome));

        // The payload matched the documented body and violated nothing: register that as the assertion
        // it is, so a test whose only check is this one is not reported as having performed none.
        Assert::assertThat($outcome->violations, Assert::isEmpty());
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
            headers: self::headers($request->headers),
            cookies: self::strings($request->cookies->all()),
            requestBody: $request->getContent(),
            requestContentType: $request->headers->get('Content-Type'),
            // PHP has one array and JSON has two containers, so `json_encode([])` is `[]` and there is
            // no PHP value the JSON test helpers — which take `array $data` — would write as `{}`.
            // A JSON request body of `[]` therefore says nothing about which the author meant, and the
            // check reads it as whichever the contract accepts.
            ambiguousEmptyRequestBody: $request->isJson(),
            responseBody: $body === false ? '' : $body,
            responseContentType: $base->headers->get('Content-Type'),
            responseHeaders: self::headerValues($base->headers),
        );
    }

    /**
     * Every value sent under each header name. A list per name rather than one string: a message may
     * send `Set-Cookie` more than once, and the contract check holds each value it sent to the
     * documented schema.
     *
     * @return array<string, non-empty-list<string>>
     */
    private static function headerValues(HeaderBag $headers): array
    {
        $out = [];
        foreach ($headers->all() as $name => $values) {
            $kept = [];
            foreach ($values as $value) {
                if (is_string($value)) {
                    $kept[] = $value;
                }
            }

            if ($kept !== []) {
                $out[(string) $name] = $kept;
            }
        }

        return $out;
    }

    /**
     * A check that passed having proved less than it looks like it did, on the run's own warning channel.
     *
     * `trigger_error()` rather than a print or a log: the runner records it against the TEST that
     * produced it, so it survives `--parallel` — a worker's issues travel home with its result, where
     * anything written to output is interleaved with a dozen other workers' or swallowed outright. It
     * never fails a passing test by itself; a suite configured to fail on warnings has asked for exactly
     * this, which is the same bargain every other library emitting one strikes.
     */
    private static function warn(?string $message): void
    {
        if ($message !== null) {
            trigger_error($message, E_USER_WARNING);
        }
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
     * The first value under each name — what a request parameter documented `in: header` is checked
     * against, since a parameter has one value.
     *
     * @return array<string, string>
     */
    private static function headers(HeaderBag $headers): array
    {
        return array_map(
            static fn (array $values): string => $values[0],
            self::headerValues($headers),
        );
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
