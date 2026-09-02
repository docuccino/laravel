<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\BaseNamedController;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\DeclaredErrorsController;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\EscapedNameException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\HttpConflictException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\InheritedApiException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\InheritingErrorsController;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\MalformedNameException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\MistypedNameException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\OtherThingMissingException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\OverridingApiException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\ThingMissingException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\UndeclaredException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\ValidationFailedException;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Workbench\App\Http\Controllers\FormController;

/**
 * `#[ErrorComponent]`, through the whole adapter.
 *
 * The name a shared error component publishes under is the name a generated client's type ends up with,
 * and until now an application could only change it by registering an `ExceptionToResponse`. The
 * attribute is the short way: it reaches the response through the same `claimComponentName()` every
 * producer uses, so there is one naming path and the precedence ladder is the ordinary one — the status
 * default the built-in tiers claim, then the attribute, then a mapper that named the body itself.
 *
 * 409 is the status throughout: the workbench documents none, so what these rows publish is theirs
 * alone. 410 stands in for a status with no default name at all.
 */

/** The action symbols the rows script, one per route registered below. */
const DECLARED_ERROR_ACTIONS = [
    'first' => DeclaredErrorsController::class.'::first',
    'second' => DeclaredErrorsController::class.'::second',
    'third' => DeclaredErrorsController::class.'::third',
    'fourth' => DeclaredErrorsController::class.'::fourth',
    'fifth' => DeclaredErrorsController::class.'::fifth',
    'sixth' => DeclaredErrorsController::class.'::sixth',
    'seventh' => DeclaredErrorsController::class.'::seventh',
    'eighth' => DeclaredErrorsController::class.'::eighth',
    'ninth' => DeclaredErrorsController::class.'::ninth',
    'tenth' => DeclaredErrorsController::class.'::tenth',
    'eleventh' => DeclaredErrorsController::class.'::eleventh',
    'twelfth' => DeclaredErrorsController::class.'::twelfth',
    'thirteenth' => DeclaredErrorsController::class.'::thirteenth',
    'fourteenth' => DeclaredErrorsController::class.'::fourteenth',
    'fifteenth' => DeclaredErrorsController::class.'::fifteenth',
    'sixteenth' => DeclaredErrorsController::class.'::sixteenth',
    'seventeenth' => DeclaredErrorsController::class.'::seventeenth',
    'eighteenth' => DeclaredErrorsController::class.'::eighteenth',
    'nineteenth' => DeclaredErrorsController::class.'::nineteenth',
    'twentieth' => DeclaredErrorsController::class.'::twentieth',
    'twentyFirst' => DeclaredErrorsController::class.'::twentyFirst',
];

/**
 * An action analysis that signals `$exceptions` — `[FQCN, status]` pairs — and returns nothing.
 *
 * @param  list<array{class-string, int}>  $exceptions
 */
function signalling(array $exceptions): ActionAnalysis
{
    return new ActionAnalysis(throws: array_map(
        static fn (array $pair): ThrownException => new ThrownException(
            $pair[0],
            $pair[1],
            [],
            ThrowConfidence::Certain,
            ThrowDisposition::Signal,
        ),
        $exceptions,
    ));
}

/**
 * A fresh stub engine per build, scripting one signalled exception per action.
 *
 * @param  array<string, array{class-string, int}>  $byAction  action name → `[FQCN, status]`
 * @return callable(): TypeEngine
 */
function declaringEngine(array $byAction): callable
{
    $analyses = [];
    foreach ($byAction as $action => $exception) {
        $analyses[DECLARED_ERROR_ACTIONS[$action]] = signalling([$exception]);
    }

    return static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: $analyses);
}

/**
 * Register `$byAction`'s routes beside the workbench's and bind an engine scripting their throws, then
 * document. Route URIs sort after everything the workbench states, so nothing here can perturb it.
 *
 * @param  array<string, array{class-string, int}>  $byAction  action name → `[FQCN, status]`
 * @param  callable(array<string, mixed>): array<string, mixed>|null  $mutateConfig
 */
function declaringBuild(array $byAction, ?callable $mutateConfig = null): GenerationResult
{
    /** @var Router $router */
    $router = app('router');
    foreach (array_keys($byAction) as $action) {
        $router->get('api/zz-declared-'.$action, [DeclaredErrorsController::class, $action]);
    }

    app()->instance(TypeEngine::class, declaringEngine($byAction)());

    return generateDocument($mutateConfig);
}

/**
 * An exception hierarchy in a directory of its own: a base carrying `#[ErrorComponent('TempFailure')]`
 * and a subclass that declares nothing, loaded here so reflection reports the written files. Its own
 * namespace per call, since a class name is claimed for the life of the process.
 *
 * @return array{dir: string, base: string, thrown: class-string}
 */
function temporaryDeclaringHierarchy(): array
{
    $dir = sys_get_temp_dir().'/docuccino-declared-src-'.uniqid('', true);
    mkdir($dir, 0777, true);

    $namespace = 'Docuccino\\Laravel\\Tests\\Temp'.bin2hex(random_bytes(6));
    $base = $dir.'/DeclaringBase.php';
    $thrown = $dir.'/ThrownException.php';

    file_put_contents($base, sprintf(
        "<?php\n\nnamespace %s;\n\nuse Docuccino\\Attributes\\ErrorComponent;\n\n#[ErrorComponent('TempFailure')]\nabstract class DeclaringBase extends \\RuntimeException {}\n",
        $namespace,
    ));
    file_put_contents($thrown, sprintf(
        "<?php\n\nnamespace %s;\n\nfinal class ThrownException extends DeclaringBase {}\n",
        $namespace,
    ));

    require $base;
    require $thrown;

    /** @var class-string $fqcn */
    $fqcn = $namespace.'\\ThrownException';

    return ['dir' => $dir, 'base' => $base, 'thrown' => $fqcn];
}

/** The route closure the locality and warm/cold harnesses replay. */
function declaringRoutes(string ...$actions): callable
{
    return static function (Router $router) use ($actions): void {
        $router->get('api/forms/{form}', [FormController::class, 'show']);
        foreach ($actions as $action) {
            $router->get('api/zz-declared-'.$action, [DeclaredErrorsController::class, $action]);
        }
    };
}

afterEach(function (): void {
    removeFragmentCacheDirs('warm');
    removeFragmentCacheDirs('cold');
    removeFragmentCacheDirs('declared');
});

it('names nothing from an #[ErrorComponent] on the action, and says so', function (string $case, array $byAction, string $published): void {
    // Reported as the attribute "changing nothing": an author put `#[ErrorComponent]` on the three
    // controller methods answering the error they wanted named, re-exported, and got the same names back.
    // `TARGET_METHOD` permits the placement and `AttributeCollector` even materialises it, but the two
    // anchors that are READ are an exception class ({@see DeclaredErrorComponent::on()}) and a render
    // method the engine analysed (`ReturnSite::$component`) — an action is neither, so nothing consults
    // it. It loses to the weakest name there is, the status default, which is how little it does.
    //
    // The row with a `#[Response]`-declared body is the reported case exactly: a body an operation
    // states itself, which no `#[ErrorComponent]` reader ever visits.
    $result = declaringBuild($byAction);
    $document = $result->document->toArray();

    $misplaced = array_values(array_filter(
        $result->diagnostics,
        static fn ($d): bool => $d->code === 'attribute.error-component-unread',
    ));

    expect($document['components']['responses'])->toHaveKey($published)
        ->and($document['components']['responses'])->not->toHaveKey('ActionNamed')
        ->and($document['components']['schemas'] ?? [])->not->toHaveKey('ActionNamed')
        // …and the placement is reported rather than ignored, naming both anchors that do work: once per
        // route, because the attribute is on each of the two actions.
        ->and($misplaced)->toHaveCount(2)
        ->and($misplaced[0]->severity)->toBe(Severity::Warning)
        ->and($misplaced[0]->message)->toContain('ActionNamed')
        ->and($misplaced[0]->help)->toContain('exception class')
        ->and($misplaced[0]->help)->toContain('render method');
})->with([
    ['a body the error tiers built', [
        'fifth' => [UndeclaredException::class, 409],
        'sixth' => [UndeclaredException::class, 409],
    ], 'Conflict'],
    ['a body the operation declared', [
        'seventh' => [UndeclaredException::class, 409],
        'eighth' => [UndeclaredException::class, 409],
    ], 'Error410'],
]);

it('publishes a declared error response under the name its #[Response] gives it', function (): void {
    // The anchor `#[ErrorComponent]` cannot be: a body the operation states itself. Named at the site
    // that declares it, which is where the author already is and where the status and the media type are
    // written down — so nothing has to be inferred about which of an operation's errors is meant.
    $document = declaringBuild([
        'ninth' => [UndeclaredException::class, 409],
        'tenth' => [UndeclaredException::class, 409],
    ])->document->toArray();

    expect($document['components']['responses'])->toHaveKey('DeclaredGone')
        ->and($document['components']['responses'])->not->toHaveKey('Error410')
        // One representation, so the name reaches the shape under it too — one concept, one name.
        ->and($document['components']['schemas'])->toHaveKey('DeclaredGone')
        ->and($document['paths']['/api/zz-declared-ninth']['get']['responses']['410']['$ref'])
        ->toBe('#/components/responses/DeclaredGone');
});

it('takes the nearest name when two #[Response] declarations name one status differently', function (): void {
    // A response component covers every representation of a status, so a status has one name — and which
    // one is the question the guard answers for every other field of the attribute: the first writer over
    // a most-specific-first set, so the nearest declaration wins. `errorComponent:` settles no differently, and
    // the name that lost is on the provenance trail where every other shadowed value is.
    $result = declaringBuild([
        'eleventh' => [UndeclaredException::class, 409],
        'ninth' => [UndeclaredException::class, 409],
    ]);
    $document = $result->document->toArray();

    $response = $document['paths']['/api/zz-declared-eleventh']['get']['responses']['410'];
    $shadowed = [];
    foreach ($response['x-docuccino']['provenance'] ?? [] as $record) {
        foreach ($record['overrode'] ?? [] as $entry) {
            if ($entry['field'] === 'component') {
                $shadowed[] = $entry['value'];
            }
        }
    }

    expect($response['x-docuccino']['facts']['component'])->toBe('DeclaredGone')
        ->and($shadowed)->toBe(['SecondName']);
});

it('lets the #[Response] that declares a status outrank the exception class\'s #[ErrorComponent]', function (): void {
    // Both contribute at `attribute`, so the guard cannot order them and the specificity rule decides:
    // the declaration nearest the operation wins. The mechanism is the one already written down — a
    // declaration replaces the status DEFAULT and nothing a producer named itself
    // ({@see DeclaredErrorComponent::mayReplace()}) — so the class anchor finds the status already named.
    $document = declaringBuild([
        'twelfth' => [ThingMissingException::class, 409],
        'first' => [ThingMissingException::class, 409],
    ])->document->toArray();

    expect($document['components']['responses'])->toHaveKey('DeclaredConflict')
        ->and($document['components']['responses'])->toHaveKey('ResourceMissing')
        // …and each operation resolves to the name written nearest it.
        ->and($document['paths']['/api/zz-declared-twelfth']['get']['responses']['409']['$ref'])
        ->toBe('#/components/responses/DeclaredConflict')
        ->and($document['paths']['/api/zz-declared-first']['get']['responses']['409']['$ref'])
        ->toBe('#/components/responses/ResourceMissing');
});

it('keeps an exception class\'s name off a response answering with more than its body', function (): void {
    // The topology the multi-representation rule exists to refuse, with the declaration on the anchor
    // that cannot see past the body it raises. `#[ErrorComponent]` is written on an exception CLASS: it
    // speaks for the error that class is, and knows nothing of the representation another producer put
    // beside it at the same status. So its name describes the one-representation body and not the pair,
    // and asking for it on both would send the seventy-five operations answering with the first up the
    // ladder behind the two answering with the second — `ValidationError_5lwwjnmg` for a rename nobody
    // asked for. Only a name written ABOUT the operation may reach a response stating several.
    $document = declaringBuild([
        'thirteenth' => [ValidationFailedException::class, 422],
        'fourteenth' => [ValidationFailedException::class, 422],
        'fifteenth' => [ValidationFailedException::class, 422],
        'sixteenth' => [ValidationFailedException::class, 422],
    ])->document->toArray();

    $names = array_keys($document['components']['responses']);

    expect($names)->toContain('ValidationError')
        ->and(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'ValidationError_')))->toBe([])
        ->and($document['paths']['/api/zz-declared-fifteenth']['get']['responses']['422']['$ref'])
        ->toBe('#/components/responses/ValidationError');
});

it('lets an action\'s component: beat the one its base controller declares', function (): void {
    // `AttributeCollector` walks the controller's parents, and the set it builds is most-specific-first
    // precisely so a child's declaration beats the base's. Every other `#[Response]` field settles that
    // way — the guard takes the first writer at equal contribution — and `errorComponent:` settles that way
    // too, so a base-controller default overridden on one action is an override rather than a standoff.
    /** @var Router $router */
    $router = app('router');
    $router->get('api/zz-inheriting-overrides', [InheritingErrorsController::class, 'overrides']);
    $router->get('api/zz-inheriting-inherits', [InheritingErrorsController::class, 'inherits']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    $result = generateDocument();
    $document = $result->document->toArray();

    $facts = static fn (string $uri): mixed => $document['paths'][$uri]['get']['responses']['410']['x-docuccino']['facts']['component'] ?? null;

    expect($facts('/api/zz-inheriting-overrides'))->toBe('ActionGone')
        ->and($facts('/api/zz-inheriting-inherits'))->toBe('BaseGone')
        ->and(diagnosticsCoded($result->diagnostics, 'attribute.response-component-contested'))->toBeEmpty();
});

it('stays quiet about an #[ErrorComponent] a base controller declares for every action under it', function (): void {
    // Measured before it was narrowed: one attribute, on one base, warned on all six routes of one child
    // — one mistake told once per route, and linear in the API from there. Nothing in a route-scoped,
    // fragment-cached pass can say it once instead: a per-build "already said" set makes what the
    // document reports a function of which routes came from cache, and a warm build reporting less than
    // a cold one is a silent degradation rather than a saving. So the report is the action's own
    // declaration, which is one route and one report by construction; the inherited placement changes no
    // name either way, and says nothing about names that were already what they will be.
    /** @var Router $router */
    $router = app('router');
    foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $action) {
        $router->get('api/zz-based-'.$action, [BaseNamedController::class, $action]);
    }

    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    expect(diagnosticsCoded(generateDocument()->diagnostics, 'attribute.error-component-unread'))->toBeEmpty();
});

it('reports an #[ErrorComponent] on an action even where the build documents no errors', function (): void {
    // `error_responses => 'none'` is what an application with no config key resolves to, and it used to
    // take the report with it: the check sat behind `ErrorResponsesExtension`'s early return. A misplaced
    // attribute is misplaced whether or not errors are being documented, so it is asked at `Finalize`,
    // which nothing gates.
    $result = declaringBuild(['fifth' => [UndeclaredException::class, 409]], static function (array $raw): array {
        $raw['error_responses'] = 'none';

        return $raw;
    });

    expect(diagnosticsCoded($result->diagnostics, 'attribute.error-component-unread'))->toHaveCount(1);
});

it('reports an errorComponent: no component key could carry', function (): void {
    // `claimComponentName()` drops an illegal name at the write and says nothing, which is the same
    // silence #187 removed from `#[ErrorComponent]` — an author who wrote `'Auth Challenge'`, a space
    // and the most likely first attempt, gets their old names back and no reason why. One mistake, one
    // remedy, so one code: the anchor that read it names itself in the message.
    $result = declaringBuild(['seventeenth' => [UndeclaredException::class, 409]]);
    $document = $result->document->toArray();

    $rejected = diagnosticsCoded($result->diagnostics, 'attribute.error-component-invalid');

    expect($rejected)->toHaveCount(1)
        ->and($rejected[0]->severity)->toBe(Severity::Warning)
        ->and($rejected[0]->message)->toContain('#[Response(status: 410')
        ->and($rejected[0]->message)->toContain('Auth Challenge')
        ->and($rejected[0]->help)->toContain('letters, digits')
        // …and the name reached nothing, exactly as it did before it was reported.
        ->and($document['paths']['/api/zz-declared-seventeenth']['get']['responses']['410']['x-docuccino']['facts'] ?? [])
        ->not->toHaveKey('component');
});

it('lets an empty errorComponent: neither publish nor block the name beside it', function (): void {
    // An empty string is no name. It is reported like any other name a key cannot carry, and — the half
    // that would have been invisible — it does not take the status's one claim on the way past, so the
    // legal declaration under it still wins.
    $result = declaringBuild(['eighteenth' => [UndeclaredException::class, 409]]);
    $document = $result->document->toArray();

    expect($document['paths']['/api/zz-declared-eighteenth']['get']['responses']['410']['x-docuccino']['facts']['component'])
        ->toBe('RealName')
        ->and(diagnosticsCoded($result->diagnostics, 'attribute.error-component-invalid'))->toHaveCount(1);
});

it('reports an errorComponent: on a status that shares no error body', function (string $action, string $status): void {
    // The argument names an ERROR component, and only an error body is ever published as one: the hoist
    // groups 4xx and 5xx and nothing else. So a name below 400 is claimed, frozen into the facts, and
    // then walked past — the same inert argument the whole branch exists to remove, one status over.
    // Making it work instead would mean componentizing success responses, which is a different feature
    // with document-wide byte impact and one the argument's own name could not describe.
    $result = declaringBuild([$action => [UndeclaredException::class, 409]]);

    $inert = diagnosticsCoded($result->diagnostics, 'attribute.error-component-unreachable');

    expect($inert)->toHaveCount(1)
        ->and($inert[0]->severity)->toBe(Severity::Warning)
        ->and($inert[0]->message)->toContain('NotAnError')
        ->and($inert[0]->message)->toContain($status.' is not an error status')
        ->and($inert[0]->help)->toContain('4xx or 5xx');
})->with([
    ['nineteenth', '200'],
    ['twentieth', '302'],
]);

it('reports an errorComponent: on a status a mapper turned into a $ref', function (): void {
    // A mapper that answers the whole status with a reference to a component it named where that component
    // is defined leaves no body here to carry another name. `ErrorResponsesExtension` has guarded the same
    // path for the class anchor since it was written; it just did so in silence, and for an argument
    // written AT the operation silence is the defect. Asked at Finalize because a status does not become a
    // `$ref` until a mapper resolves, one phase after the claim is written.
    /** @var Router $router */
    $router = app('router');
    $router->get('api/zz-declared-twentyFirst', [DeclaredErrorsController::class, 'twentyFirst']);

    app()->instance(TypeEngine::class, declaringEngine(['twentyFirst' => [NotFoundHttpException::class, 404]])());
    Docuccino::extend(declaringRefMapper(NotFoundHttpException::class, '404', 'SharedNotFound'));

    $result = generateDocument();

    $unreachable = diagnosticsCoded($result->diagnostics, 'attribute.error-component-unreachable');

    expect($result->document->toArray()['paths']['/api/zz-declared-twentyFirst']['get']['responses']['404']['$ref'])
        ->toBe('#/components/responses/SharedNotFound')
        ->and($unreachable)->toHaveCount(1)
        ->and($unreachable[0]->message)->toContain('NamesTheReference')
        ->and($unreachable[0]->message)->toContain('is a reference to a shared component')
        ->and($unreachable[0]->help)->toContain('Name the component at its own definition');
});

it('names an undeclared exception\'s error after its status, as it always did', function (): void {
    $document = declaringBuild([
        'first' => [UndeclaredException::class, 409],
        'second' => [UndeclaredException::class, 409],
    ])->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('Conflict')
        ->and($document['components']['responses'])->toHaveKey('Conflict');
});

it('publishes an error under the name its exception declares', function (): void {
    $document = declaringBuild([
        'first' => [ThingMissingException::class, 409],
        'second' => [ThingMissingException::class, 409],
    ])->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('ResourceMissing')
        ->and($document['components']['responses'])->toHaveKey('ResourceMissing')
        // The built-in tier's status name is gone, not published beside it.
        ->and($document['components']['schemas'])->not->toHaveKey('Conflict')
        ->and($document['paths']['/api/zz-declared-first']['get']['responses']['409']['$ref'])
        ->toBe('#/components/responses/ResourceMissing');
});

it('leaves an error only one operation states inline, declared or not', function (): void {
    // The attribute names a SHARED component, and a body one operation states is never shared — so a
    // declared error alone publishes exactly what an undeclared one alone publishes, which is nothing.
    // What repeats decides WHETHER a body is hoisted; a declaration decides only what the component is
    // called, and it cannot promote a body the document states once.
    $result = declaringBuild(['first' => [ThingMissingException::class, 409]]);
    $document = $result->document->toArray();

    $response = $document['paths']['/api/zz-declared-first']['get']['responses']['409'];

    expect($response)->not->toHaveKey('$ref')
        ->and($response['content']['application/json']['schema'])->not->toHaveKey('$ref')
        ->and($document['components']['schemas'])->not->toHaveKey('ResourceMissing')
        ->and($document['components']['responses'])->not->toHaveKey('ResourceMissing')
        // Nothing to report either: the author's declaration is neither wrong nor ignored, and a warning
        // on every one-off error would fire where its reader can do nothing but throw the exception twice.
        ->and(diagnosticsCoded($result->diagnostics, 'attribute.error-component-invalid'))->toBeEmpty()
        ->and(diagnosticsCoded($result->diagnostics, 'attribute.error-component-contested'))->toBeEmpty()
        // …and the name is on the response all the same, which is what makes the SECOND operation to state
        // this body publish `ResourceMissing` rather than `Conflict`.
        ->and($response['x-docuccino']['facts']['component'])->toBe('ResourceMissing');
});

it('names a status that has no default name of its own', function (): void {
    // 410 has no reason phrase in the table, so nothing claims a name for it and the body would be
    // `Error410`. The declaration is the only name it will ever have.
    $document = declaringBuild([
        'first' => [ThingMissingException::class, 410],
        'second' => [ThingMissingException::class, 410],
    ])->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('ResourceMissing')
        ->and($document['components']['schemas'])->not->toHaveKey('Error410');
});

it('inherits a declaration from a base exception that carries one', function (): void {
    // PHP does not inherit class attributes; an application's `ApiException` base naming its component
    // once is the shape the reader walks parents for.
    $document = declaringBuild([
        'first' => [InheritedApiException::class, 409],
        'second' => [InheritedApiException::class, 409],
    ])->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('ApiFailure')
        ->and($document['components']['schemas'])->not->toHaveKey('Conflict');
});

it('lets the nearest declaring class win over the base it inherits from', function (): void {
    $document = declaringBuild([
        'first' => [OverridingApiException::class, 409],
        'second' => [OverridingApiException::class, 409],
    ])->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('PolicyRefused')
        ->and($document['components']['schemas'])->not->toHaveKey('ApiFailure');
});

/**
 * An application's own mapper for one exception, naming the body it builds — the escape hatch for when
 * an attribute on the class cannot say enough.
 */
function declaringAppMapper(string $fqcn, string $status, string $name): ExceptionToResponse
{
    return new class($fqcn, $status, $name) implements ExceptionToResponse
    {
        public function __construct(
            private readonly string $fqcn,
            private readonly string $status,
            private readonly string $name,
        ) {}

        public function supports(ThrownException $exception, RouteContext $context): bool
        {
            return is_a($exception->exceptionFqcn, $this->fqcn, true);
        }

        public function producer(): string
        {
            return 'integration:acme';
        }

        public function toResponse(ThrownException $exception, RouteContext $context, ComponentRegistry $components): ?ResponseDraft
        {
            $by = Contribution::integration('acme');

            $draft = new ResponseDraft($this->status);
            $draft->claimComponentName($this->name, $by);
            $draft->setDescription('Conflict', $by);
            $draft->content('application/json')->set('type', 'object', $by);
            $draft->content('application/json')->set('properties', ['detail' => ['type' => 'string']], $by);

            return $draft;
        }
    };
}

/**
 * An application's own mapper that answers a whole status with a `$ref` to a response component it
 * registered — the shape of a document whose error bodies are shared and named where they are defined.
 */
function declaringRefMapper(string $fqcn, string $status, string $component): ExceptionToResponse
{
    return new class($fqcn, $status, $component) implements ExceptionToResponse
    {
        public function __construct(
            private readonly string $fqcn,
            private readonly string $status,
            private readonly string $component,
        ) {}

        public function supports(ThrownException $exception, RouteContext $context): bool
        {
            return is_a($exception->exceptionFqcn, $this->fqcn, true);
        }

        public function producer(): string
        {
            return 'integration:acme';
        }

        public function toResponse(ThrownException $exception, RouteContext $context, ComponentRegistry $components): ?ResponseDraft
        {
            $components->referenceResponse($this->component, [
                'description' => 'Error',
                'content' => ['application/problem+json' => ['schema' => ['type' => 'object']]],
            ]);

            $draft = new ResponseDraft($this->status);
            $draft->setRef('#/components/responses/'.$this->component, Contribution::integration('acme'));

            return $draft;
        }
    };
}

it('lets a registered mapper\'s name beat the one the exception declares', function (): void {
    // The ordering that matters most: one exception class can render several different bodies, and only
    // the mapper that built one can tell them apart. A name on the class replaces the STATUS default;
    // it does not overrule a producer that named the body.
    Docuccino::extend(declaringAppMapper(ThingMissingException::class, '409', 'FromMapper'));

    $document = declaringBuild([
        'first' => [ThingMissingException::class, 409],
        'second' => [ThingMissingException::class, 409],
    ])->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('FromMapper')
        ->and($document['components']['schemas'])->not->toHaveKey('ResourceMissing')
        ->and($document['components']['schemas'])->not->toHaveKey('Conflict');
});

it('refuses a declared name no component key could carry and tells the class that declared it', function (): void {
    // `claimComponentName()` reads an illegal name as no declaration at all and says nothing, so the
    // attribute would be a line of code that does nothing for no stated reason. The adapter has a
    // diagnostic channel the draft does not, so it refuses the name where it READS it and reports —
    // once per route the class is signalled from, riding that route's fragment like every other.
    $result = declaringBuild([
        'first' => [MalformedNameException::class, 409],
        'second' => [MalformedNameException::class, 409],
    ]);
    $document = $result->document->toArray();
    $rejected = diagnosticsCoded($result->diagnostics, 'attribute.error-component-invalid');

    expect($document['components']['schemas'])->toHaveKey('Conflict')
        // The status default the framework-errors tier claimed stands, and nothing was named `Error409`.
        ->and($document['components']['schemas'])->not->toHaveKey('Error409')
        // Nowhere at all — not as a key, not in a `$ref`, not in the provenance facts.
        ->and(json_encode($document))->not->toContain('Not Found!')
        ->and($rejected)->toHaveCount(2)
        ->and($rejected[0]->message)->toContain(MalformedNameException::class)
        ->and($rejected[0]->message)->toContain('Not Found!')
        ->and($rejected[0]->severity)->toBe(Severity::Warning)
        // The reader has to go and edit the attribute, so the diagnostic points at the file it is on.
        ->and($rejected[0]->source?->file)->toContain('MalformedNameException.php')
        // Nothing else reports it: core's hoist keeps `components.name-invalid` for a document that
        // already states an illegal name, which only an overlay can now do.
        ->and(diagnosticsCoded($result->diagnostics, 'components.name-invalid'))->toBeEmpty();
});

it('documents a route whose exception mistyped the attribute, and prints no path into the document', function (): void {
    // `#[ErrorComponent(5)]` is a one-character typo, and constructing the attribute to find out throws a
    // `TypeError` whose message names the absolute path of the file it was written on. Reading the
    // arguments instead keeps the route buildable: the class simply named nothing, which is what a
    // malformed argument says. A build that let the throw out would collapse the route to a skeleton and
    // put this machine's paths into the emitted document.
    $result = declaringBuild([
        'first' => [MistypedNameException::class, 409],
        'second' => [MistypedNameException::class, 409],
    ]);
    $document = $result->document->toArray();

    $failed = array_values(array_filter(
        diagnosticsCoded($result->diagnostics, 'route.build-failed'),
        static fn ($diagnostic): bool => str_contains((string) $diagnostic->routeSignature, 'zz-declared'),
    ));

    expect($failed)->toBeEmpty()
        ->and($document['components']['schemas'])->toHaveKey('Conflict')
        // No route collapsed, so nothing carried a `TypeError`'s message — and with it this machine's
        // paths — into the document's diagnostics.
        ->and(json_encode($document))->not->toContain(dirname(__DIR__, 4))
        ->and($document['paths']['/api/zz-declared-first']['get']['responses'])->toHaveKey('409');
});

it('quotes a refused name exactly as the attribute wrote it', function (): void {
    // Nothing validated the string an attribute carries, and the diagnostic reporting it goes to a JSON
    // report and to the emitted document as well as to a terminal. It states what it read; the console
    // escapes at the write (`RendersDiagnostics`), which is the only reader that needs it to.
    $result = declaringBuild([
        'first' => [EscapedNameException::class, 409],
        'second' => [EscapedNameException::class, 409],
    ]);
    $rejected = diagnosticsCoded($result->diagnostics, 'attribute.error-component-invalid');

    expect($rejected[0]->message)->toContain("Not\x1B[31mFound");
});

it('does not let a name it refused contest one it accepted', function (): void {
    // A refused name is not a declaration, so the status has one declaration and not two: the legal name
    // stands. Counting the refused one as a contestant would let a typo on an unrelated exception strip
    // a correctly named response back to its default.
    /** @var Router $router */
    $router = app('router');
    $router->get('api/zz-declared-first', [DeclaredErrorsController::class, 'first']);
    $router->get('api/zz-declared-second', [DeclaredErrorsController::class, 'second']);

    $both = signalling([[ThingMissingException::class, 409], [MalformedNameException::class, 409]]);
    app()->instance(TypeEngine::class, WorkbenchEngine::make(analysisOverrides: [
        DECLARED_ERROR_ACTIONS['first'] => $both,
        DECLARED_ERROR_ACTIONS['second'] => $both,
    ]));

    $result = generateDocument();
    $document = $result->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('ResourceMissing')
        ->and($document['components']['schemas'])->not->toHaveKey('Conflict')
        ->and(diagnosticsCoded($result->diagnostics, 'attribute.error-component-contested'))->toBeEmpty()
        ->and(diagnosticsCoded($result->diagnostics, 'attribute.error-component-invalid'))->toHaveCount(2);
});

it('shares one component between two exceptions that declare one name over one body', function (): void {
    // Two classes, one name, byte-identical bodies under one status: that is one error with two ways of
    // being thrown, and one component is the honest answer.
    $document = declaringBuild([
        'first' => [ThingMissingException::class, 409],
        'second' => [OtherThingMissingException::class, 409],
    ])->document->toArray();

    $names = array_values(array_filter(
        array_map(strval(...), array_keys($document['components']['schemas'])),
        static fn (string $name): bool => str_starts_with($name, 'ResourceMissing'),
    ));

    expect($names)->toBe(['ResourceMissing'])
        ->and($document['paths']['/api/zz-declared-first']['get']['responses']['409']['$ref'])
        ->toBe($document['paths']['/api/zz-declared-second']['get']['responses']['409']['$ref']);
});

it('records the same declaring class whichever throw the analyzer reports first', function (): void {
    // Two classes declaring ONE name for one status agree on what to publish, so there is no contest and
    // the name is stable either way. The provenance still has to name one of them, and `declaredBy` — with
    // the file and line the reader is sent to — is emitted, so picking the last one seen would put the
    // order the engine happened to report throws in into the document's bytes.
    /** @var Router $router */
    $router = app('router');
    $router->get('api/zz-declared-first', [DeclaredErrorsController::class, 'first']);

    $declarer = static function (array $response): array {
        foreach ($response['x-docuccino']['provenance'] as $record) {
            if (in_array('component', $record['fields'], true)) {
                return $record['source'];
            }
        }

        return [];
    };

    $sources = [];
    foreach ([[ThingMissingException::class, OtherThingMissingException::class], [OtherThingMissingException::class, ThingMissingException::class]] as $order) {
        app()->instance(TypeEngine::class, WorkbenchEngine::make(analysisOverrides: [
            DECLARED_ERROR_ACTIONS['first'] => signalling([[$order[0], 409], [$order[1], 409]]),
        ]));

        $document = generateDocument()->document->toArray();
        $sources[] = $declarer($document['paths']['/api/zz-declared-first']['get']['responses']['409']);
    }

    // The lowest FQCN: a fact about the two classes, not about which of them arrived first.
    expect($sources[0])->toBe($sources[1])
        ->and($sources[0]['symbol'])->toBe(OtherThingMissingException::class)
        ->and($sources[0]['file'])->toEndWith('OtherThingMissingException.php');
});

it('retires a declared name two different bodies contest, and warns', function (): void {
    // The same name over two statuses is two different responses asking for one type name. Neither keeps
    // it; each is published under a name derived from its own content, and the build says so.
    $result = declaringBuild([
        'first' => [ThingMissingException::class, 409],
        'second' => [ThingMissingException::class, 409],
        'third' => [OtherThingMissingException::class, 410],
        'fourth' => [OtherThingMissingException::class, 410],
    ]);
    $document = $result->document->toArray();

    $names = array_values(array_filter(
        array_map(strval(...), array_keys($document['components']['schemas'])),
        static fn (string $name): bool => str_starts_with($name, 'ResourceMissing'),
    ));

    expect($names)->toHaveCount(2)
        ->and($names)->not->toContain('ResourceMissing')
        ->and($names)->each->toMatch('/^ResourceMissing_[a-z2-7]{8}$/')
        ->and(diagnosticsCoded($result->diagnostics, 'components.name-collision'))->not->toBeEmpty();
});

it('keeps the default name when two exceptions name one operation\'s one status differently', function (): void {
    // One response, two declarations. Handing it to whichever exception the engine reported first would
    // make a published type name a function of encounter order, so neither takes it and the author is
    // told which two classes to reconcile.
    /** @var Router $router */
    $router = app('router');
    $router->get('api/zz-declared-first', [DeclaredErrorsController::class, 'first']);
    $router->get('api/zz-declared-second', [DeclaredErrorsController::class, 'second']);

    $contested = signalling([[ThingMissingException::class, 409], [OverridingApiException::class, 409]]);
    app()->instance(TypeEngine::class, WorkbenchEngine::make(analysisOverrides: [
        DECLARED_ERROR_ACTIONS['first'] => $contested,
        DECLARED_ERROR_ACTIONS['second'] => $contested,
    ]));

    $result = generateDocument();
    $document = $result->document->toArray();
    $contest = diagnosticsCoded($result->diagnostics, 'attribute.error-component-contested');

    expect($document['components']['schemas'])->toHaveKey('Conflict')
        ->and($document['components']['schemas'])->not->toHaveKey('ResourceMissing')
        ->and($document['components']['schemas'])->not->toHaveKey('PolicyRefused')
        ->and($contest)->not->toBeEmpty()
        ->and($contest[0]->message)->toContain(ThingMissingException::class)
        ->and($contest[0]->message)->toContain(OverridingApiException::class);
});

it('does not move an operation an exception it never throws learns to name', function (): void {
    // Locality. The workbench form route's own 404 must be byte-identical before and after two unrelated
    // routes start publishing a declared 409.
    assertUnaffectedByUnrelatedRoute(
        declaringRoutes(),
        static function (Router $router): void {
            $router->get('api/zz-declared-first', [DeclaredErrorsController::class, 'first']);
            $router->get('api/zz-declared-second', [DeclaredErrorsController::class, 'second']);
        },
        'GET /api/forms/{form}',
        declaringEngine([
            'first' => [ThingMissingException::class, 409],
            'second' => [ThingMissingException::class, 409],
        ]),
    );
});

it('publishes the same bytes and the same diagnostics on a warm fragment-cache build', function (): void {
    // The declaration is read while a route is built, so it travels on the operation fragment or not at
    // all — a warm hit that lost it would republish the status default under a different name.
    $engine = declaringEngine([
        'first' => [ThingMissingException::class, 409],
        'second' => [ThingMissingException::class, 409],
    ]);

    $warm = assertWarmEqualsCold(declaringRoutes('first'), declaringRoutes('first', 'second'), $engine);

    expect($warm->document->toArray()['components']['schemas'])->toHaveKey('ResourceMissing');
});

it('replays a refused name\'s warning on a warm fragment-cache build', function (): void {
    // The refusal is decided while a route is built, so the warning travels on that route's fragment or
    // not at all. A warm build that reported less than a cold one would be a silent degradation — the
    // author fixes the typo they were told about and never hears about the one they were not.
    $engine = declaringEngine([
        'first' => [MalformedNameException::class, 409],
        'second' => [MalformedNameException::class, 409],
    ]);

    $warm = assertWarmEqualsCold(declaringRoutes('first'), declaringRoutes('first', 'second'), $engine);

    expect(diagnosticsCoded($warm->diagnostics, 'attribute.error-component-invalid'))->toHaveCount(2);
});

it('invalidates a fragment when the BASE class that declares the name is edited', function (): void {
    // The inheritance decision's other half: the name comes from a file the throwing route never
    // mentions, so that file has to key the fragment or a warm build serves the old name. The hierarchy
    // is WRITTEN for this row rather than edited in place — a tracked fixture a test rewrites is one
    // crash away from a dirty checkout, and one parallel worker away from hashing bytes it never wrote.
    ['dir' => $dir, 'base' => $baseFile, 'thrown' => $thrown] = temporaryDeclaringHierarchy();

    try {
        fragmentCacheDir('declared');

        /** @var Router $router */
        $router = app('router');
        $router->get('api/zz-declared-first', [DeclaredErrorsController::class, 'first']);

        $engine = new CountingTypeEngine(declaringEngine(['first' => [$thrown, 409]])());
        app()->instance(TypeEngine::class, $engine);

        $document = generateDocument()->document->toArray();
        $engine->analyzeCount = 0;

        // The base really is what named this response, so the file edited below is really the one the
        // answer came from.
        expect($document['paths']['/api/zz-declared-first']['get']['responses']['409']['x-docuccino']['facts']['component'])
            ->toBe('TempFailure');

        generateDocument();
        expect($engine->analyzeCount)->toBe(0);

        file_put_contents($baseFile, file_get_contents($baseFile)."\n// fragment-cache dependency probe\n");
        generateDocument();

        expect($engine->analyzeCount)->toBeGreaterThan(0);
    } finally {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }
});

it('leaves a response that is only a reference for its component to name', function (): void {
    // A mapper can answer a status with a `$ref` to a shared response. A reference states no body of its
    // own, so there is nothing here for the exception's name to rename — the component it points at was
    // named where it was defined.
    /** @var Router $router */
    $router = app('router');
    $router->get('api/zz-declared-first', [DeclaredErrorsController::class, 'first']);

    app()->instance(TypeEngine::class, declaringEngine(['first' => [HttpConflictException::class, 409]])());
    Docuccino::extend(declaringRefMapper(HttpConflictException::class, '409', 'SharedConflict'));

    $document = generateDocument()->document->toArray();

    $response = $document['paths']['/api/zz-declared-first']['get']['responses']['409'];

    expect($response)->toHaveKey('$ref')
        ->and($response['$ref'])->toBe('#/components/responses/SharedConflict')
        ->and($response['x-docuccino']['facts'] ?? [])->not->toHaveKey('component')
        ->and($document['components']['responses'])->not->toHaveKey('ResourceMissing');
});
