<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Laravel\Integrations\Support\RequestPageSizeReader;
use Docuccino\Laravel\Tests\Support\StubTraceScope;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Workbench\App\Support\ListPageSize;

/**
 * The page-size recovery's mechanics, in-process over real php-parser nodes and REAL reflection: the
 * source-range correlation that ties a read inside a callee back to the paginator argument that named it is
 * proven against {@see ListPageSize}'s actual lines, not a hand-built range. Only the type scope is stubbed;
 * the real engine proves the same recovery end-to-end in the `fixture` group.
 *
 * Every case pins the same contract from one side or the other: a key is documented when the size
 * argument's VALUE was followed back to a request read, and nothing is documented otherwise. "Followed"
 * means value flow — a key the code reads to decide something else never becomes a size, however close to
 * the return it sits.
 */

/** The terminals a visitor would hand the reader — the reader itself decides which it knows. */
const PAGE_SIZE_TERMINALS = ['paginate', 'simplePaginate', 'cursorPaginate', 'paginateList'];

/**
 * Walks one snippet (or one real file's source) through the reader the way a visitor does: `observe()` on
 * every node, `terminal()` on every paginating call.
 */
function walkPageSize(
    RequestPageSizeReader $reader,
    string $code,
    string $file,
    string $receiver = 'Illuminate\\Http\\Request',
): void {
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code) ?? [];
    $scope = new StubTraceScope(
        $receiver === '' ? new ScalarT('int') : new ClassT($receiver),
        file: $file,
    );

    $traverser = new NodeTraverser(new class($reader, $scope) extends NodeVisitorAbstract
    {
        public function __construct(
            private readonly RequestPageSizeReader $reader,
            private readonly StubTraceScope $scope,
        ) {}

        public function enterNode(Node $node): ?int
        {
            $this->reader->observe($node, $this->scope);

            $called = ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
                && $node->name instanceof Node\Identifier
                    ? $node->name->toString()
                    : null;

            if ($called !== null && in_array($called, PAGE_SIZE_TERMINALS, true)) {
                $this->reader->terminal($node, $called, $this->scope);
            }

            return null;
        }
    });
    $traverser->traverse($ast);
}

/** The real clamp helper's own source, walked under its own path so its real lines are what correlate. */
function walkClampHelper(RequestPageSizeReader $reader): void
{
    $file = (new ReflectionClass(ListPageSize::class))->getFileName();
    expect($file)->toBeString();

    walkPageSize($reader, (string) file_get_contents((string) $file), (string) $file);
}

/** A caller body, as if it were the terminal's own method: `<statements>` then the paginating call. */
function walkCaller(RequestPageSizeReader $reader, string $body, string $receiver = 'Illuminate\\Http\\Request'): void
{
    walkPageSize($reader, "<?php\n".$body."\n", 'caller.php', $receiver);
}

it('follows a size argument through a local variable into a helper on another class', function (): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$perPage = \Workbench\App\Support\ListPageSize::clamp($request, 15, 100);
$page = $this->paginate($perPage);');
    walkClampHelper($reader);

    $recovered = $reader->recovered();

    expect($recovered?->key)->toBe('per_page')
        // The read's fallback is the helper's own `$default` parameter, which belongs to whichever caller
        // supplied it — so there is no default to publish.
        ->and($recovered?->default)->toBeNull();
});

it('records the file the recovered fact was written in', function (): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$perPage = \Workbench\App\Support\ListPageSize::clamp($request);
$page = $this->paginate($perPage);');
    walkClampHelper($reader);

    $reader->recovered();

    expect(array_map('basename', $reader->dependencyFiles()))->toContain('ListPageSize.php');
});

it('reads a request key written straight into the terminal, with its literal fallback', function (): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginate($request->integer("per_page", 15));');

    expect($reader->recovered()?->key)->toBe('per_page')
        ->and($reader->recovered()?->default)->toBe(15);
});

it('reads a request key through an inline clamp, whose bounds it deliberately drops', function (): void {
    // A clamp pins an out-of-range value to its nearest bound rather than rejecting it, so `minimum` /
    // `maximum` would tell a consumer their value is invalid when it is merely adjusted.
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginate(max(1, min($request->integer("limit", 25), 100)));');

    expect($reader->recovered()?->key)->toBe('limit')
        ->and($reader->recovered()?->default)->toBe(25);
});

it('reads a size passed as a named argument', function (): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginate(perPage: $request->integer("per_page", 30));');

    expect($reader->recovered()?->key)->toBe('per_page');
});

it('correlates only the reads inside the callee the size argument named', function (): void {
    // `ListPageSize::ambiguous()` reads two keys in one body; `clamp()` reads one. Both live in the file
    // walked below, so a recovery that ignored source ranges would see three reads for either callee.
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$perPage = \Workbench\App\Support\ListPageSize::ambiguous($request);
$page = $this->paginate($perPage);');
    walkClampHelper($reader);

    expect($reader->recovered())->toBeNull();
});

it('keeps the key but drops a default two reads of it disagree on', function (): void {
    // Determinism: a default settled by whichever read the walk happened to see first would not be a fact.
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$perPage = \Workbench\App\Support\ListPageSize::repeated($request);
$page = $this->paginate($perPage);');
    walkClampHelper($reader);

    expect($reader->recovered()?->key)->toBe('per_page')
        ->and($reader->recovered()?->default)->toBeNull();
});

it('claims nothing for a size the endpoint does not read off the request', function (string $body): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, $body);
    walkClampHelper($reader);

    expect($reader->recovered())->toBeNull();
})->with([
    // The model's own $perPage: no argument was written at all.
    'a bare terminal' => ['$page = $this->paginate();'],
    // Fixed at the call site — the case v0.5.0 stopped claiming a key for.
    'a literal size' => ['$page = $this->paginate(20);'],
    // A helper that takes the request and reads nothing off it.
    'a helper that reads nothing' => ['$perPage = \Workbench\App\Support\ListPageSize::fixed($request);
$page = $this->paginate($perPage);'],
    // A parameter of the enclosing method: the fixture `paginateList(int $perPage)` shape.
    'the enclosing method\'s own parameter' => ['$page = $this->paginate($perPage);'],
    // No such class, so no body to correlate against.
    'an unresolvable callee' => ['$perPage = \Nope\Missing::clamp($request);
$page = $this->paginate($perPage);'],
    // A second write means the variable names no single origin.
    'a variable written twice' => ['$perPage = \Workbench\App\Support\ListPageSize::clamp($request);
$perPage = 20;
$page = $this->paginate($perPage);'],
    // One variable hop only; a chain of them is dataflow guesswork.
    'a second variable hop' => ['$size = \Workbench\App\Support\ListPageSize::clamp($request);
$perPage = $size;
$page = $this->paginate($perPage);'],
    // `query()` with no key returns the whole bag and names nothing.
    'a keyless accessor' => ['$page = $this->paginate($request->query());'],
    // A non-literal key names nothing either.
    'a computed key' => ['$page = $this->paginate($request->integer($key));'],
]);

it('claims nothing for a terminal whose signature it does not know', function (): void {
    // A custom terminal's own argument order is unknown, so its arguments are never read positionally —
    // the vendor terminal it forwards to is reached by the trace anyway.
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginateList($request->integer("per_page", 15));');

    expect($reader->recovered())->toBeNull();
});

it('claims nothing when the receiver of the read is not a request', function (): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginate($settings->integer("per_page", 15));', receiver: 'Workbench\App\Models\Form');

    expect($reader->recovered())->toBeNull();
});

it('declines a first-class callable rather than reading it as a size', function (): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$fn = $this->paginate(...);');

    expect($reader->recovered())->toBeNull();
});

it('follows a read named in a local inside the callee out through its return', function (): void {
    // `ListPageSize::limit()` names the read first and clamps the local — the other half of how apps write
    // a clamp, and a key that is not `per_page`, since nothing here matches on the name.
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$perPage = \Workbench\App\Support\ListPageSize::limit($request);
$page = $this->paginate($perPage);');
    walkClampHelper($reader);

    expect($reader->recovered()?->key)->toBe('limit')
        ->and($reader->recovered()?->default)->toBe(15);
});

it('claims nothing for a key the callee reads to decide something else', function (string $helper): void {
    // The whole point of the reachability rule: both helpers READ the request, both live at lines the size
    // argument correlates against, and neither read is the value they answer with.
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$perPage = \Workbench\App\Support\ListPageSize::'.$helper.'($request);
$page = $this->paginate($perPage);');
    walkClampHelper($reader);

    expect($reader->recovered())->toBeNull();
})->with([
    // `match ($request->input('preset'))` — the key picks the arm, the arms hold the sizes.
    'a match subject' => ['preset'],
    // A read inside a closure the body never calls, at a line inside the helper's own span.
    'a closure the body never calls' => ['lazy'],
]);

it('reads a size through every form a value flows along', function (string $argument, string $key, ?int $default): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginate('.$argument.');');

    expect($reader->recovered()?->key)->toBe($key)
        ->and($reader->recovered()?->default)->toBe($default);
})->with([
    // An int cast is how an app reading with `input()` still hands `paginate()` an integer.
    'an int cast' => ['(int) $request->input("per_page", 15)', 'per_page', 15],
    // `??` and both ternary spellings take their value from the arms, so a read in one is the size.
    'a null coalesce' => ['$request->input("per_page") ?? 15', 'per_page', null],
    'a ternary arm' => ['$wide ? $request->integer("per_page", 50) : 15', 'per_page', 50],
    'a short ternary' => ['$request->integer("per_page", 50) ?: 15', 'per_page', 50],
    'a match arm' => ['match ($tier) { "pro" => $request->integer("per_page", 50), default => 15 }', 'per_page', 50],
]);

it('claims nothing for a key that only chooses between sizes', function (string $body): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, $body);

    expect($reader->recovered())->toBeNull();
})->with([
    // The subject and the conditions decide WHICH literal is used; none of them is the value.
    'a match subject' => ['$page = $this->paginate(match ($request->input("preset")) { "small" => 10, default => 25 });'],
    'a ternary condition' => ['$page = $this->paginate($request->input("preset") === "small" ? 10 : 25);'],
    // An arrow function has no return statement at all, and the size is written beside it.
    'an arrow function nobody calls' => ['$threshold = fn () => $request->integer("threshold", 5);
$page = $this->paginate(20);'],
    // Arithmetic over a read is a size the endpoint no longer honours, key and default both.
    'arithmetic over a read' => ['$page = $this->paginate($request->integer("per_page", 200) * 2);'],
]);

it('reads a key through every request accessor that names one', function (string $accessor): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginate($request->'.$accessor.'("per_page", 15));');

    expect($reader->recovered()?->key)->toBe('per_page')
        ->and($reader->recovered()?->default)->toBe(15);
})->with(['integer', 'input', 'query', 'get', 'post']);

it('claims nothing for an accessor that does not name one query key', function (string $call): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginate($request->'.$call.');');

    expect($reader->recovered())->toBeNull();
})->with([
    // Not a size at all, and not in the accessor list either — the degradation for anything unlisted.
    'boolean' => ['boolean("per_page")'],
    'string' => ['string("per_page")'],
    // A header is not a query parameter, whatever it is named.
    'header' => ['header("per_page")'],
    // The whole bag, under no key.
    'all' => ['all()'],
]);

it('reads a key through every clamp helper it knows', function (string $argument): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginate('.$argument.');');

    expect($reader->recovered()?->key)->toBe('per_page')
        ->and($reader->recovered()?->default)->toBe(15);
})->with([
    'min' => ['min($request->integer("per_page", 15), 100)'],
    'max' => ['max(1, $request->integer("per_page", 15))'],
    'intval' => ['intval($request->input("per_page", 15))'],
    // Case is not part of the name PHP resolves.
    'MAX' => ['MAX(1, $request->integer("per_page", 15))'],
]);

it('claims nothing through a function that is not a clamp', function (string $argument): void {
    // A function that CHANGES the value publishes a size the endpoint does not honour, so only the
    // pass-through helpers are followed — an unknown one recovers nothing.
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->paginate('.$argument.');');

    expect($reader->recovered())->toBeNull();
})->with([
    'ceil' => ['(int) ceil($request->integer("per_page", 15) / 2)'],
    'array_sum' => ['array_sum([$request->integer("per_page", 15)])'],
    'abs' => ['abs($request->integer("per_page", 15))'],
]);

it('reads the size argument of every paginating terminal whose signature names one', function (string $terminal): void {
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$page = $this->'.$terminal.'($request->integer("per_page", 15));');

    expect($reader->recovered()?->key)->toBe('per_page')
        ->and($reader->recovered()?->default)->toBe(15);
})->with(['paginate', 'simplePaginate', 'cursorPaginate']);

it('claims nothing for a spread, which names no argument position at all', function (): void {
    // `paginate(...$args)` reads whatever the array holds first, which is not this call's to say.
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$args = [\Workbench\App\Support\ListPageSize::clamp($request)];
$page = $this->paginate(...$args);');
    walkClampHelper($reader);

    expect($reader->recovered())->toBeNull();
});

it('retires the local on every form that writes one', function (string $write): void {
    // One grammar for the fold and for the guard: a form the reader still trusted after one of these
    // writes would publish the first read's key for a size the endpoint no longer takes from it.
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$perPage = $request->integer("per_page", 15);
'.$write.'
$page = $this->paginate($perPage);');

    expect($reader->recovered())->toBeNull();
})->with([
    'a compound assignment' => ['$perPage *= 2;'],
    'a concatenating assignment' => ['$perPage .= "0";'],
    'a coalescing assignment' => ['$perPage ??= 20;'],
    'a reference assignment' => ['$perPage = &$other;'],
    'a reference TO the local' => ['$alias = &$perPage;'],
    'an increment' => ['$perPage++;'],
    'a pre-decrement' => ['--$perPage;'],
    'list destructuring' => ['list($perPage, $rest) = [40, 1];'],
    'short destructuring' => ['[$perPage, $rest] = [40, 1];'],
    'nested destructuring' => ['[[$perPage], $rest] = [[40], 1];'],
    'keyed destructuring' => ['["size" => $perPage] = ["size" => 40];'],
    'a foreach value binding' => ['foreach ([40] as $perPage) {}'],
    'a foreach key binding' => ['foreach ([40 => 1] as $perPage => $ignored) {}'],
    'a static declaration' => ['static $perPage = 40;'],
    'a global declaration' => ['global $perPage;'],
    'an unset' => ['unset($perPage);'],
    'a catch binding' => ['try { $x = 1; } catch (\RuntimeException $perPage) {}'],
    // Neither of these names a local at all, so no local of this body can be followed.
    'a variable variable' => ['$$name = 40;'],
    'an extract' => ['extract($data);'],
]);

it('keeps the key when the write lands on another local', function (): void {
    // The control on the list above: retiring more than the language writes would lose every real reading.
    $reader = new RequestPageSizeReader;
    walkCaller($reader, '$perPage = $request->integer("per_page", 15);
$other *= 2;
[$first, $second] = [1, 2];
$page = $this->paginate($perPage);');

    expect($reader->recovered()?->key)->toBe('per_page');
});

it('degrades the page-size fact rather than the route when a helper will not load', function (): void {
    // A helper whose parent is an optional dev dependency throws `Error: Class … not found` out of the
    // AUTOLOADER, not a ReflectionException — and this runs inside a per-route Throwable catch, so an
    // escape costs the route its whole document and leaves one `route.build-failed` behind. One page-size
    // key fewer is the honest loss.
    $loader = static function (string $class): void {
        if ($class === 'Workbench\\App\\Support\\UnloadableClamp') {
            throw new Error('Class "Vendor\\Absent\\BaseClamp" not found');
        }
    };
    spl_autoload_register($loader);

    try {
        $reader = new RequestPageSizeReader;
        walkCaller($reader, '$perPage = \Workbench\App\Support\UnloadableClamp::clamp($request);
$page = $this->paginate($perPage);');

        expect($reader->recovered())->toBeNull()
            ->and($reader->dependencyFiles())->toBe([]);
    } finally {
        spl_autoload_unregister($loader);
    }
});
