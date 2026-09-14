<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Schema\ClassAnnotations;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Exceptions\DeclaredErrorComponent;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\BaseNamedController;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\DeclaredErrorsController;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\DescribedMissingException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\DoublyDescribedException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\EmptyDescribedException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\EscapedNameException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\FileDescribedException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\HttpConflictException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\InheritedApiException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\InheritedDescribedException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\InheritingErrorsController;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\MalformedNameException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\MistypedDescriptionException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\MistypedNameException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\OtherThingMissingException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\OverridingApiException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\RedescribedMissingException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\RenamedDescribedException;
use Docuccino\Laravel\Tests\Fixtures\DeclaredErrors\RequestDescribedException;
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

it('quotes a refused name as text rather than as the control sequence it was', function (): void {
    // Nothing validated the string an attribute carries, and the diagnostic reporting it is published
    // under `x-docuccino.diagnostics` as well as printed. The document is not a terminal we render, so
    // the sequence is made visible where the diagnostic is built rather than at any one reader.
    $result = declaringBuild([
        'first' => [EscapedNameException::class, 409],
        'second' => [EscapedNameException::class, 409],
    ]);
    $rejected = diagnosticsCoded($result->diagnostics, 'attribute.error-component-invalid');

    expect($rejected[0]->message)->toContain('Not\x1B[31mFound')
        ->and($rejected[0]->message)->not->toContain("\x1b");
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

/*
 * What an exception class SAYS about the error it names.
 *
 * `#[ErrorComponent]` gives the shared component a name a consumer can catch; `#[Description]` beside it
 * gives that name a meaning. OpenAPI holds `description` on any Schema Object, and
 * `SchemaClassAttributes::HONOURED` already promises `#[Description]` is read as the schema description
 * of every schema class — so a schema class that publishes nothing from one is the odd case, not the
 * other way round. Somebody catching `ResourceMissing` in a generated client cannot see the codebase,
 * and a type with no sentence beside it tells them only its status.
 *
 * The one rule everything below follows: the sentence comes from the SAME declaration that produced the
 * NAME. The component is deduped by body and named for a cause, so a sentence settled any other way ends
 * up describing a different cause's error under this one's name.
 */

/** The description published on a schema component, or null where it publishes none. */
function describedComponent(array $document, string $name): ?string
{
    $description = $document['components']['schemas'][$name]['description'] ?? null;

    return is_string($description) ? $description : null;
}

it('publishes what the exception class declaring the name says the error is', function (): void {
    $document = declaringBuild([
        'first' => [DescribedMissingException::class, 409],
        'second' => [DescribedMissingException::class, 409],
    ])->document->toArray();

    expect(describedComponent($document, 'ResourceMissing'))
        ->toBe('No record matches the identifier in the path.')
        // On the SCHEMA, which is the type a generated client is written against. The response component
        // keeps the wording its arms state, which `spoken()` settles and this does not touch.
        ->and($document['components']['responses']['ResourceMissing']['description'])->toBe('Conflict');
});

it('publishes no description for an exception class that names its error and says nothing about it', function (): void {
    // The control. An absent sentence is the honest answer for a class that wrote none, and the name is
    // published exactly as it was before there was a sentence to publish at all.
    $document = declaringBuild([
        'first' => [ThingMissingException::class, 409],
        'second' => [ThingMissingException::class, 409],
    ])->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('ResourceMissing')
        ->and(describedComponent($document, 'ResourceMissing'))->toBeNull();
});

it('keeps the sentence where one class states it and another naming the same error states none', function (): void {
    // Silence is not dissent. A class that describes nothing has no wording of its own to lose, so it
    // takes the one the rest of the name's claimants agreed on — the rule a shared response's prose
    // already follows, one bucket over.
    $result = declaringBuild([
        'first' => [DescribedMissingException::class, 409],
        'second' => [ThingMissingException::class, 409],
    ]);

    expect(describedComponent($result->document->toArray(), 'ResourceMissing'))
        ->toBe('No record matches the identifier in the path.')
        ->and(diagnosticsCoded($result->diagnostics, 'components.description-conflict'))->toBeEmpty();
});

it('publishes no description where two classes naming one error describe it differently, and warns', function (): void {
    // Two causes spelled one name and disagree about what it means. Publishing either would put one
    // author's sentence on a type the other also named, and which one won would depend on which routes
    // the application happens to have — the defect the naming ladder exists to prevent, one field over.
    // A Schema Object's `description` is OPTIONAL, so unlike a response's wording this can refuse: absent
    // is vague and true, and the reader is told so rather than left hunting for a sentence they wrote.
    $result = declaringBuild([
        'first' => [DescribedMissingException::class, 409],
        'second' => [RedescribedMissingException::class, 409],
    ]);
    $document = $result->document->toArray();
    $conflict = diagnosticsCoded($result->diagnostics, 'components.description-conflict');

    expect($document['components']['schemas'])->toHaveKey('ResourceMissing')
        ->and(describedComponent($document, 'ResourceMissing'))->toBeNull()
        ->and($conflict)->toHaveCount(1)
        ->and($conflict[0]->severity)->toBe(Severity::Warning)
        ->and($conflict[0]->message)->toContain('ResourceMissing')
        ->and($conflict[0]->message)->toContain('No record matches the identifier in the path.')
        ->and($conflict[0]->message)->toContain('The record was removed and will not come back.')
        // The NAME is untouched: the disagreement is about prose, and prose has never decided identity
        // here, so nothing climbed the ladder and no client's type was renamed.
        ->and(diagnosticsCoded($result->diagnostics, 'components.name-collision'))->toBeEmpty();
});

it('inherits both halves from a base exception that declares them', function (): void {
    // The same walk the name takes: an application base naming and describing its error once is the shape
    // worth serving, and a subclass that declares nothing is that error.
    $document = declaringBuild([
        'first' => [InheritedDescribedException::class, 409],
        'second' => [InheritedDescribedException::class, 409],
    ])->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('DescribedFailure')
        ->and(describedComponent($document, 'DescribedFailure'))->toBe('The request could not be completed.');
});

it('leaves a base\'s sentence behind when a subclass renames the error', function (): void {
    // The rule that makes the whole thing safe, at the one place it costs something. `RenamedFailure` is a
    // different error from the `DescribedFailure` the base describes, so the base's sentence is not about
    // it — carrying it down would publish prose about one error on another's type, which is worse than no
    // prose at all. The price is a subclass that renames and describes nothing publishing nothing, and
    // there is deliberately no diagnostic: a base describing the error IT names while its subclasses name
    // their own is correct, so a report would fire at every throw where the author has nothing to fix.
    $result = declaringBuild([
        'first' => [RenamedDescribedException::class, 409],
        'second' => [RenamedDescribedException::class, 409],
    ]);
    $document = $result->document->toArray();

    expect($document['components']['schemas'])->toHaveKey('RenamedFailure')
        ->and(describedComponent($document, 'RenamedFailure'))->toBeNull()
        ->and($document['components']['schemas'])->not->toHaveKey('DescribedFailure')
        ->and(json_encode($document))->not->toContain('The request could not be completed.')
        ->and(diagnosticsCoded($result->diagnostics, 'components.description-conflict'))->toBeEmpty();
});

it('refuses a #[Description] a schema cannot hold and says which form it was', function (string $case, string $exception, string $component, string $code, string $fragment): void {
    // Every form `DescribedText` refuses, through this anchor — one row per form, so a form that stopped
    // being refused here fails rather than quietly publishing something an operation-level declaration
    // meant. `file:` in particular: no application root reaches a schema mapper to resolve a path
    // against, so it is refused here for exactly the reason it is refused on a property.
    $result = declaringBuild([
        'first' => [$exception, 409],
        'second' => [$exception, 409],
    ]);
    $document = $result->document->toArray();
    $refused = diagnosticsCoded($result->diagnostics, $code);

    expect($document['components']['schemas'])->toHaveKey($component)
        ->and(describedComponent($document, $component))->toBeNull()
        // One per route the class is signalled from, riding that route's fragment like every other.
        ->and($refused)->toHaveCount(2)
        ->and($refused[0]->severity)->toBe(Severity::Warning)
        ->and($refused[0]->message)->toContain($exception)
        ->and($refused[0]->message)->toContain($fragment)
        // Sited where the author has to go: the file the attribute is written in, and the route that
        // asked. The reader alone cannot say either, so the adapter adds them.
        ->and($refused[0]->source?->file)->toContain('Exception.php')
        ->and($refused[0]->routeSignature)->toContain('zz-declared');
})->with([
    ['a markdown file', FileDescribedException::class, 'FileDescribed', 'attribute.property-unsupported', '#[Description(file: …)]'],
    ['a request body', RequestDescribedException::class, 'RequestDescribed', 'attribute.property-unsupported', '#[Description(request: true)]'],
    ['neither text nor file', EmptyDescribedException::class, 'EmptyDescribed', 'attribute.description-unusable', 'neither `text:` nor `file:`'],
]);

it('documents a route whose exception mistyped the #[Description], and prints no path into the document', function (): void {
    // The sibling of the mistyped `#[ErrorComponent]` row above, and it owes the same discipline:
    // `#[Description(5)]` cannot be constructed, and the `TypeError` that says so names the absolute file
    // it was written in. The reader swallows it, so the class simply described nothing.
    $result = declaringBuild([
        'first' => [MistypedDescriptionException::class, 409],
        'second' => [MistypedDescriptionException::class, 409],
    ]);
    $document = $result->document->toArray();

    $failed = array_values(array_filter(
        diagnosticsCoded($result->diagnostics, 'route.build-failed'),
        static fn ($diagnostic): bool => str_contains((string) $diagnostic->routeSignature, 'zz-declared'),
    ));

    expect($failed)->toBeEmpty()
        // The name it declared still publishes: one broken attribute does not cost the other.
        ->and($document['components']['schemas'])->toHaveKey('MistypedDescription')
        ->and(describedComponent($document, 'MistypedDescription'))->toBeNull()
        ->and(json_encode($document))->not->toContain(dirname(__DIR__, 4));
});

it('describes only the component the declaration named, not a body sharing its status', function (): void {
    // An undeclared body keys on its status alone and publishes what it always published. A sentence
    // written about `ResourceMissing` has nothing to say about the `Conflict` beside it, and the two
    // never meet: the claim is in the schema's dedupe scope, so they are different buckets.
    $document = declaringBuild([
        'first' => [DescribedMissingException::class, 409],
        'second' => [UndeclaredException::class, 409],
    ])->document->toArray();

    expect(describedComponent($document, 'ResourceMissing'))
        ->toBe('No record matches the identifier in the path.')
        ->and($document['components']['schemas'])->toHaveKey('Conflict')
        ->and(describedComponent($document, 'Conflict'))->toBeNull();
});

it('does not move an operation an exception it never throws learns to describe', function (): void {
    // Locality. Two unrelated routes starting to publish a described 409 must leave the workbench form
    // route's own 404 byte-identical.
    assertUnaffectedByUnrelatedRoute(
        declaringRoutes(),
        static function (Router $router): void {
            $router->get('api/zz-declared-first', [DeclaredErrorsController::class, 'first']);
            $router->get('api/zz-declared-second', [DeclaredErrorsController::class, 'second']);
        },
        'GET /api/forms/{form}',
        declaringEngine([
            'first' => [DescribedMissingException::class, 409],
            'second' => [DescribedMissingException::class, 409],
        ]),
    );
});

it('publishes the same description and the same diagnostics on a warm fragment-cache build', function (): void {
    // The sentence is read while a route is built, so it travels on that route's fragment or not at all —
    // and the file it came from is already keyed, because the class that declares the name is in the
    // hierarchy whose files this route records. A warm hit that lost either half would publish a
    // described component cold and a bare one warm.
    $engine = declaringEngine([
        'first' => [DescribedMissingException::class, 409],
        'second' => [DescribedMissingException::class, 409],
    ]);

    $warm = assertWarmEqualsCold(declaringRoutes('first'), declaringRoutes('first', 'second'), $engine);

    expect(describedComponent($warm->document->toArray(), 'ResourceMissing'))
        ->toBe('No record matches the identifier in the path.');
});

it('replays a refused #[Description]\'s warning on a warm fragment-cache build', function (): void {
    // A warm build reporting less than a cold one is a silent degradation: the author fixes the
    // declaration they were told about and never hears about the one they were not.
    $engine = declaringEngine([
        'first' => [FileDescribedException::class, 409],
        'second' => [FileDescribedException::class, 409],
    ]);

    $warm = assertWarmEqualsCold(declaringRoutes('first'), declaringRoutes('first', 'second'), $engine);

    expect(diagnosticsCoded($warm->diagnostics, 'attribute.property-unsupported'))->toHaveCount(2);
});

/**
 * What EVERY form of a `#[Description]` on a class must publish and report, stated here and not asked of
 * any reader — `[case, exception class, the sentence a schema publishes, the codes it raises]`.
 *
 * The domain is the declaration's own parameter space: `text:` and `file:` present or absent is four
 * combinations, `request:` adds the one case that only arises beside a text, and an argument PHP cannot
 * construct is the fifth thing an author can write. Nothing else is reachable, so these rows are the
 * whole of it.
 *
 * @return list<array{string, class-string, ?string, list<string>}>
 */
function classSentenceContract(): array
{
    return [
        ['text alone', DescribedMissingException::class, 'No record matches the identifier in the path.', []],
        ['no declaration', ThingMissingException::class, null, []],
        ['file alone', FileDescribedException::class, null, ['attribute.property-unsupported']],
        ['text and file', DoublyDescribedException::class, null, ['attribute.description-unusable']],
        ['neither text nor file', EmptyDescribedException::class, null, ['attribute.description-unusable']],
        ['text with request', RequestDescribedException::class, null, ['attribute.property-unsupported']],
        ['an argument PHP cannot construct', MistypedDescriptionException::class, null, []],
    ];
}

it('reads a class\'s own sentence the same way wherever the product publishes one', function (string $case, string $fqcn, ?string $published, array $codes): void {
    // Two publishers read one fact: a schema minted for a class, and the shared error component an
    // exception class names. Covering both is not the same as their AGREEING, so this asserts they agree
    // — against the table above rather than against either of them, since a guard that asks the code for
    // its own rule agrees with whatever the code does.
    $diagnostics = [];
    [$schema] = ClassAnnotations::describe([], $fqcn);
    ClassAnnotations::stated($fqcn, $diagnostics);

    // The schema-class publisher.
    expect($schema['description'] ?? null)->toBe($published)
        ->and(array_map(static fn ($d): string => $d->code, $diagnostics))->toBe($codes);

    // The error-component publisher, through the whole adapter.
    $declaration = DeclaredErrorComponent::on($fqcn);
    $result = declaringBuild(['first' => [$fqcn, 409], 'second' => [$fqcn, 409]]);
    $document = $result->document->toArray();
    $component = $declaration?->name ?? 'Conflict';

    $raised = array_values(array_unique(array_map(
        static fn ($d): string => $d->code,
        array_filter($result->diagnostics, static fn ($d): bool => str_starts_with($d->code, 'attribute.') && str_contains($d->message, $fqcn)),
    )));

    expect($document['components']['schemas'])->toHaveKey($component)
        ->and(describedComponent($document, $component))->toBe($published)
        ->and($raised)->toBe($codes);
})->with(classSentenceContract());

it('byte-locks a document whose error components say what they are', function (): void {
    // The corpus had no document in this population at all: no golden carried an `#[ErrorComponent]` on an
    // exception class, so nothing in it could move when a described one started publishing. Both halves of
    // the rule are here — a class that names and describes its own error, and a base that does both for a
    // subclass declaring neither — over a warm build, which is where a fact read while a route was built
    // is easiest to lose.
    $engine = declaringEngine([
        'first' => [DescribedMissingException::class, 409],
        'second' => [DescribedMissingException::class, 409],
        'third' => [InheritedDescribedException::class, 410],
        'fourth' => [InheritedDescribedException::class, 410],
    ]);

    $warm = assertWarmEqualsCold(
        declaringRoutes('first', 'third'),
        declaringRoutes('first', 'second', 'third', 'fourth'),
        $engine,
    );
    $document = $warm->document->toArray();

    expect(describedComponent($document, 'ResourceMissing'))->toBe('No record matches the identifier in the path.')
        ->and(describedComponent($document, 'DescribedFailure'))->toBe('The request could not be completed.');

    assertGolden('workbench-described-error.uir.json', (new UirEmitter)->emit($warm->document));
});
