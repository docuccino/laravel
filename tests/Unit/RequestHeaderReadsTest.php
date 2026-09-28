<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Extensions\RequestHeaderReads;
use Docuccino\Laravel\Extensions\RequestHeadersExtension;
use Docuccino\Laravel\Tests\Support\StubTraceScope;
use Docuccino\Laravel\Tests\Support\TraceScript;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * The reader's grammar over real php-parser nodes, with only the type scope stubbed: `$request` is the
 * framework request and every other receiver is not. The real engine proves the receivers are typed that
 * way in the `fixture` group.
 *
 * @return array<string, array{name: string, spellings: list<string>, locations: list<mixed>}>
 */
function readRequestHeaders(string ...$statements): array
{
    $reads = new RequestHeaderReads;
    $scope = new StubTraceScope(new ClassT('stdClass'), variableTypes: ['request' => new ClassT('Illuminate\\Http\\Request')]);

    foreach ($statements as $statement) {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse("<?php\n".$statement.";\n") ?? [];
        (new NodeTraverser(new class($reads, $scope) extends NodeVisitorAbstract
        {
            public function __construct(private readonly RequestHeaderReads $reads, private readonly StubTraceScope $scope) {}

            public function enterNode(Node $node): ?int
            {
                $this->reads->enterNode($node, $this->scope);

                return null;
            }
        }))->traverse($ast);
    }

    return $reads->headers();
}

it('reads a header named through each accessor the framework offers', function (string $read): void {
    expect(array_keys(readRequestHeaders($read)))->toBe(['x-request-id']);
})->with([
    'header()' => '$request->header("X-Request-Id")',
    'header() with a fallback' => '$request->header("X-Request-Id", "none")',
    'hasHeader()' => '$request->hasHeader("X-Request-Id")',
    'the facade\'s header()' => '\\Illuminate\\Support\\Facades\\Request::header("X-Request-Id")',
    'the facade\'s hasHeader()' => '\\Illuminate\\Support\\Facades\\Request::hasHeader("X-Request-Id")',
    'the facade\'s default global alias' => '\\Request::header("X-Request-Id")',
    'the bag\'s get()' => '$request->headers->get("X-Request-Id")',
    'the bag\'s has()' => '$request->headers->has("X-Request-Id")',
    'the bag\'s all()' => '$request->headers->all("X-Request-Id")',
]);

it('reads nothing where no header is named, or the receiver is not the request', function (string $read): void {
    expect(readRequestHeaders($read))->toBe([]);
})->with([
    'a name that is not a literal' => '$request->header($name)',
    'every header at once' => '$request->header()',
    'an empty name' => '$request->header("")',
    'a name no client could send' => '$request->header("X Request Id")',
    'a first-class callable' => '$request->header(...)',
    'another request accessor' => '$request->input("X-Request-Id")',
    'a cookie' => '$request->cookie("X-Request-Id")',
    'the bearer token' => '$request->bearerToken()',
    'a response header being set' => '$response->header("X-Request-Id", "1")',
    'another object\'s headers bag' => '$response->headers->get("X-Request-Id")',
    'a bag method that names no header' => '$request->headers->set("X-Request-Id", "1")',
    'another facade' => '\\Illuminate\\Support\\Facades\\Response::header("X-Request-Id")',
    'another facade\'s default global alias' => '\\Response::header("X-Request-Id")',
    'a namespaced class the alias is not' => '\\App\\Request::header("X-Request-Id")',
]);

it('reads one header under every spelling the framework looks it up by, named the same whichever came first', function (): void {
    $forward = readRequestHeaders('$request->header("x_request_id")', '$request->header("X-Request-Id")', '$request->headers->get("x-request-id")');
    $backward = readRequestHeaders('$request->headers->get("x-request-id")', '$request->header("X-Request-Id")', '$request->header("x_request_id")');

    // `_` is read as `-` because that is what the header bag looks up, so no spelling keeps it.
    expect($forward['x-request-id']['spellings'])->toBe(['X-Request-Id', 'x-request-id'])
        ->and($backward['x-request-id']['spellings'])->toBe($forward['x-request-id']['spellings'])
        ->and($forward['x-request-id']['name'])->toBe('X-Request-Id')
        ->and($backward['x-request-id']['name'])->toBe('X-Request-Id')
        ->and($forward['x-request-id']['locations'])->toHaveCount(3);
});

it('names every method the framework looks for on a FormRequest by name', function (): void {
    $source = (string) file_get_contents((string) (new ReflectionClass('Illuminate\\Foundation\\Http\\FormRequest'))->getFileName());
    preg_match_all('/method_exists\(\$this,\s*\'(\w+)\'\)/', $source, $probes);

    $found = array_values(array_unique($probes[1]));
    sort($found);

    // A plausible minimum, so a pattern that stopped matching fails here rather than agreeing with nothing.
    expect(count($found))->toBeGreaterThanOrEqual(4)
        ->and($found)->toBe(RequestHeadersExtension::FORM_REQUEST_PROBES);
});

/**
 * The header parameters the producer publishes for an action whose trace reads `$reads`, by name.
 *
 * @param  list<array<string, mixed>>|null  $security  the requirement a security producer already set
 * @param  array<string, array<string, mixed>>  $registered  schemes a security producer registered
 * @return list<string>
 */
function publishedRequestHeaders(string $reads, ?array $security = null, array $registered = []): array
{
    $trace = TraceScript::forChain($reads, 'stdClass', variableTypes: ['request' => new ClassT('Illuminate\\Http\\Request')]);
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], '/api/widgets'),
        actionRef: new ActionRef('widgets.php', 'WidgetController', 'index'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(traces: ['WidgetController::index' => static fn (TraceVisitor $v) => $trace($v)]),
        document: new DocumentConfig('default', []),
    );
    foreach ($registered as $name => $scheme) {
        $context->components->registerSecurityScheme($name, $scheme);
    }

    $operation = new OperationDraft;
    if ($security !== null) {
        $operation->setSecurity($security, Contribution::integration('security'));
    }
    (new RequestHeadersExtension)->handle($operation, $context);

    return array_map(static fn (string $key): string => substr($key, strlen('header:')), $operation->parameterKeys());
}

it('never publishes a header the transport or a proxy sets rather than the client', function (string $header): void {
    // Read beside an ordinary header, so a producer that published nothing at all fails here too.
    expect(publishedRequestHeaders('[$request->header("'.$header.'"), $request->header("X-Request-Id")]'))->toBe(['X-Request-Id']);
})->with(RequestHeadersExtension::TRANSPORT_HEADERS);

it('publishes a header outside the transport set, whatever case it is read in', function (string $header): void {
    expect(publishedRequestHeaders('$request->header("'.$header.'")'))->toBe([$header]);
})->with(['X-Forwarded-By-Nothing', 'Host-Override', 'User-Agent', 'If-Match']);

it('refuses every transport header under the spelling a proxy writes it in', function (): void {
    expect(publishedRequestHeaders('[$request->header("X-Forwarded-For"), $request->header("CF-Connecting-IP"), $request->headers->get("HOST")]'))->toBe([]);
});

it('leaves an extension-registered apiKey scheme\'s header to the scheme the operation requires', function (): void {
    $scheme = ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Widget-Key'];

    // Only a scheme THIS operation's requirement names: one registered by some other route is no
    // statement about this one, and consulting it would make the answer depend on route order.
    expect(publishedRequestHeaders('$request->header("x-widget-key")', [['widgetKey' => []]], ['widgetKey' => $scheme]))->toBe([])
        ->and(publishedRequestHeaders('$request->header("x-widget-key")', null, ['widgetKey' => $scheme]))->toBe(['x-widget-key']);
});
