<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Routing\VendorRoutePolicy;
use Docuccino\Laravel\Support\CanGate;
use Docuccino\Laravel\Support\GateDenial;
use Docuccino\Laravel\Support\GateInternals;
use Docuccino\Laravel\Support\GatePoliciesDigestContributor;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Awning;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Banner;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Fascia;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Hoarding;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Illuminated;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Kiosk;
use Docuccino\Laravel\Tests\Fixtures\Authorization\KioskController;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Lightbox;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Marquee;
use Docuccino\Laravel\Tests\Fixtures\Authorization\MarqueeAccess;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Placard;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Policies\BannerPolicy;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Policies\BaseSignagePolicy;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Policies\FasciaPolicy;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Policies\IlluminatedPolicy;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Policies\KioskPolicy;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Policies\PlacardPolicy;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Policies\SignagePolicy;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Policies\TurnstilePolicy;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Policies\WeatherproofPolicy;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Pylon;
use Docuccino\Laravel\Tests\Fixtures\Authorization\PylonAccess;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Signage;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Totem;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Turnstile;
use Docuccino\Laravel\Tests\Fixtures\Authorization\Weatherproof;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Auth\Access\Gate as IlluminateGate;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Gate;

/**
 * `authorization.gate-cannot-deny`: the implicit 403 is synthesized from the PRESENCE of a `can:` gate,
 * and a policy method whose whole body is `return true;` makes it an error no request can provoke.
 *
 * Routes here are registered the way an application writes them — `->can(...)` on a real router, real
 * policy classes, resolution left to Laravel's own convention except where a row is about explicit
 * registration — and every silence row states the shape it is silent ON. The response itself always
 * stays published: a diagnostic cannot drop a real error, and nothing here is certain enough to.
 */
function gateRoutes(): void
{
    $router = app('router');

    // Behind auth, conventional policy, `viewAny` is a literal `return true;` → the 403 is dead.
    $router->get('api/kiosks', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('viewAny', Kiosk::class);

    // Same, resolved through the ROUTE PARAMETER form of the gate rather than a class name.
    $router->get('api/kiosks/{kiosk}', [KioskController::class, 'show'])
        ->middleware('auth:web')
        ->can('view', 'kiosk');

    // An explicitly registered policy no naming convention would find.
    $router->get('api/marquees', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('view', Marquee::class);

    // A policy method that plainly denies.
    $router->patch('api/kiosks-updated', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('update', Kiosk::class);

    // The counter-example: one expression, from a call, naming neither the user nor a permission — and
    // it denies. Anything looser than a literal `return true;` would have hidden a reachable 403 here.
    $router->get('api/kiosks-inspected', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('inspect', Kiosk::class);

    // `true` is in the body and it is reached conditionally.
    $router->get('api/kiosks-audited', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('audit', Kiosk::class);

    // A policy carrying a before() method, which answers for every ability it covers.
    $router->get('api/placards', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('view', Placard::class);

    // An ability-only gate: no model argument, so it resolves to a Gate::define'd closure and no policy
    // method exists to read.
    $router->get('api/kiosks-abilities', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('viewAny');

    // A `signed` middleware alongside an undeniable gate — the 403 is reachable through the signature.
    $router->get('api/kiosks-signed', [KioskController::class, 'index'])
        ->middleware(['auth:web', 'signed'])
        ->can('viewAny', Kiosk::class);

    // An undeniable gate on a route a GUEST can reach, whose policy method refuses guests: Laravel
    // denies that without ever calling the method.
    $router->get('api/kiosks-public', [KioskController::class, 'index'])
        ->can('view', Kiosk::class);

    // The same route with a guest-admitting method, which is why the row above is about guests and not
    // about the body.
    $router->get('api/kiosks-public-any', [KioskController::class, 'index'])
        ->can('viewAny', Kiosk::class);

    // The ability method is INHERITED. The gate resolves to SignagePolicy, which declares nothing —
    // so the class the report names has to be the one the body is written in.
    $router->get('api/signage', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('viewAny', Signage::class);

    // Same shape reached through a trait rather than a parent.
    $router->get('api/banners', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('viewAny', Banner::class);

    // No policy for the model at all — nothing registered, and nothing at the conventional name.
    $router->get('api/awnings', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('viewAny', Awning::class);

    // The policy exists and declares no such ability, so the gate falls through to a Gate::define'd
    // closure this never reads.
    $router->get('api/kiosks-destroyed', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('destroy', Kiosk::class);

    // `signed` AFTER the gate. Route::can() appends its middleware, so this is the only order in which
    // the second signal is reached by the loop rather than by the signal check ahead of it.
    $router->get('api/kiosks-signed-last', [KioskController::class, 'index'])
        ->can('viewAny', Kiosk::class)
        ->middleware('signed');

    // The gate as `Authorize::using()` writes it: the middleware's own class name, no `can:` alias.
    $router->get('api/marquees-using', [KioskController::class, 'index'])
        ->middleware(['auth:web', Authorize::using('view', Marquee::class)]);

    // `signed` and `verified` written the same way round — their own static constructors render the
    // middleware's class name, exactly as `Authorize::using()` does. A reader that knows only the alias
    // reports these routes for a 403 the signature and the verification really do produce.
    $router->get('api/kiosks-signed-class', [KioskController::class, 'index'])
        ->middleware(['auth:web', ValidateSignature::relative()])
        ->can('viewAny', Kiosk::class);

    $router->get('api/kiosks-signed-absolute', [KioskController::class, 'index'])
        ->middleware(['auth:web', ValidateSignature::absolute()])
        ->can('viewAny', Kiosk::class);

    $router->get('api/kiosks-verified-class', [KioskController::class, 'index'])
        ->middleware(['auth:web', EnsureEmailIsVerified::redirectTo('verification.notice')])
        ->can('viewAny', Kiosk::class);

    // The authorization middleware naming no ability at all: it IS the middleware, so the 403 publishes
    // — an ability nothing defines denies whatever meets it — and no policy stands behind it to read.
    $router->get('api/kiosks-unnamed', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('');

    // A policy the container is the only way to BUILD, for the row that proves nothing builds one.
    $router->get('api/turnstiles', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('viewAny', Turnstile::class);

    // The author has already dropped the response, so there is no 403 to report on.
    $router->get('api/kiosks-muted', [KioskController::class, 'muted'])
        ->middleware('auth:web')
        ->can('viewAny', Kiosk::class);

    $router->getRoutes()->refreshNameLookups();
}

beforeEach(function (): void {
    bindStubEngine();
    Gate::policy(Marquee::class, MarqueeAccess::class);
    TurnstilePolicy::$constructed = 0;
    gateRoutes();
});

/**
 * One authenticated `->can('viewAny', Kiosk::class)` route as a context, for the rows that ask
 * {@see GateDenial} directly rather than through a build — the ones about what it was handed.
 */
function gateDenialContext(): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/kiosks', middleware: ['auth:web', 'can:viewAny,'.Kiosk::class]),
        actionRef: new ActionRef('', KioskController::class, 'index'),
        attributes: new AttributeSet([]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', [], authMiddleware: 'auth*'),
    );
}

/** The gate-reachability diagnostics of one build, keyed by the route signature they name. */
function gateFindings(): array
{
    $found = [];
    foreach (generateDocument()->diagnostics as $diagnostic) {
        if ($diagnostic->code === 'authorization.gate-cannot-deny') {
            $found[(string) $diagnostic->routeSignature] = $diagnostic;
        }
    }

    return $found;
}

it('reports the gate a policy method cannot deny, and keeps publishing the 403', function (): void {
    $findings = gateFindings();
    $document = generateDocument()->document->toArray();

    expect($findings)->toHaveKey('GET /api/kiosks')
        ->and($findings['GET /api/kiosks']->severity->value)->toBe('info')
        ->and($findings['GET /api/kiosks']->message)->toBe(
            "Publishes a 403 from ->can('viewAny', Docuccino\\Laravel\\Tests\\Fixtures\\Authorization\\Kiosk::class), "
            .'but Docuccino\\Laravel\\Tests\\Fixtures\\Authorization\\Policies\\KioskPolicy::viewAny() returns true unconditionally, '
            .'so the gate cannot deny.',
        )
        ->and($findings['GET /api/kiosks']->help)->toContain('#[IgnoreResponse(403)]')
        // The point of the diagnostic is that the response STAYS. Dropping a real error needs a
        // certainty this does not have, so the document is unchanged and the report is the whole fix.
        ->and($document['paths']['/api/kiosks']['get']['responses'])->toHaveKey('403');
});

it('answers the question without ever building a policy', function (): void {
    // The claim the whole of {@see GateInternals} exists for, EXECUTED rather than asserted. The one
    // accessor the Gate contract publishes for this — `getPolicyFor()` — ends in
    // `$container->make($policy)`, so reaching for it runs the policy's constructor, resolves whatever
    // it injects and fires every `Container::resolving` hook the application registered, at
    // documentation-build time. Every other policy in this suite has a trivial constructor and would
    // let that regression through in silence.
    expect(gateFindings())->toHaveKey('GET /api/turnstiles')
        ->and(gateFindings()['GET /api/turnstiles']->message)->toContain(TurnstilePolicy::class.'::viewAny()')
        ->and(TurnstilePolicy::$constructed)->toBe(0);

    // Anti-vacuity: the counter does move, and what moves it is the very accessor resolution is
    // written not to use — so the zero above is the check's doing and not the fixture's.
    app(GateContract::class)->getPolicyFor(Turnstile::class);

    expect(TurnstilePolicy::$constructed)->toBe(1);
});

it('resolves the gate through the route parameter form', function (): void {
    expect(gateFindings())->toHaveKey('GET /api/kiosks/{kiosk}')
        ->and(gateFindings()['GET /api/kiosks/{kiosk}']->message)
        ->toContain("->can('view', 'kiosk')")
        ->toContain('KioskPolicy::view()');
});

it('resolves a policy nothing but an explicit Gate::policy() registration would find', function (): void {
    expect(gateFindings())->toHaveKey('GET /api/marquees')
        ->and(gateFindings()['GET /api/marquees']->message)->toContain('MarqueeAccess::view()');
});

it('names the class the ability method is declared in, not the one the gate resolved to', function (): void {
    // The blocker this row exists for: the reflection that reads the body already answers with the
    // DECLARING class, and naming the resolved policy instead sent the reader to a file with no such
    // method in it. SignagePolicy declares nothing at all.
    expect(gateFindings())->toHaveKey('GET /api/signage')
        ->and(gateFindings()['GET /api/signage']->message)
        ->toContain(BaseSignagePolicy::class.'::viewAny()')
        ->not->toContain(SignagePolicy::class.'::viewAny()');
});

it('reports an ability method a policy takes from a trait', function (): void {
    // The other way a body lands outside the policy's own file. PHP flattens a trait method into the
    // using class, so the name is already the one a reader would call — and the FILE the check reads,
    // and gates actionability on, is the trait's.
    expect(gateFindings())->toHaveKey('GET /api/banners')
        ->and(gateFindings()['GET /api/banners']->message)->toContain(BannerPolicy::class.'::viewAny()');
});

it('stays silent on every gate shape that can deny', function (string $signature): void {
    expect(gateFindings())->not->toHaveKey($signature);
})->with([
    'a policy method that reads state' => ['PATCH /api/kiosks-updated'],
    'one expression from a call, no $user and no permission' => ['GET /api/kiosks-inspected'],
    'true returned conditionally' => ['GET /api/kiosks-audited'],
    'a policy with a before() method' => ['GET /api/placards'],
    'an ability-only gate with no policy behind it' => ['GET /api/kiosks-abilities'],
    'a signed middleware holding the same 403 up' => ['GET /api/kiosks-signed'],
    'a guest-reachable route whose policy method refuses guests' => ['GET /api/kiosks-public'],
    'a 403 the author already dropped' => ['GET /api/kiosks-muted'],
    'no policy for the model at all' => ['GET /api/awnings'],
    'a policy that declares no such ability method' => ['GET /api/kiosks-destroyed'],
    'a signed middleware the gate was declared BEFORE' => ['GET /api/kiosks-signed-last'],
    // The false positive M1 was: each of these holds the 403 up on its own, and each is written in the
    // spelling a reader of the alias alone does not see.
    'ValidateSignature::relative() holding the same 403 up' => ['GET /api/kiosks-signed-class'],
    'ValidateSignature::absolute() holding the same 403 up' => ['GET /api/kiosks-signed-absolute'],
    'EnsureEmailIsVerified::redirectTo() holding the same 403 up' => ['GET /api/kiosks-verified-class'],
    'an authorization middleware naming no ability' => ['GET /api/kiosks-unnamed'],
]);

it('still publishes the 403 on every route it stays silent about', function (string $path, string $method): void {
    $document = generateDocument()->document->toArray();

    expect($document['paths'][$path][$method]['responses'] ?? [])->toHaveKey('403');
})->with([
    ['/api/kiosks-updated', 'patch'],
    ['/api/kiosks-inspected', 'get'],
    ['/api/kiosks-audited', 'get'],
    ['/api/placards', 'get'],
    ['/api/kiosks-abilities', 'get'],
    ['/api/kiosks-signed', 'get'],
    ['/api/kiosks-public', 'get'],
    ['/api/awnings', 'get'],
    ['/api/kiosks-destroyed', 'get'],
    ['/api/kiosks-signed-last', 'get'],
    ['/api/kiosks-signed-class', 'get'],
    ['/api/kiosks-signed-absolute', 'get'],
    ['/api/kiosks-verified-class', 'get'],
    // Anti-vacuity for the silence row above it: the middleware is read, so the 403 is still there.
    ['/api/kiosks-unnamed', 'get'],
]);

it('synthesizes and reports the 403 of a gate written as Authorize::using()', function (): void {
    // `Route::can()` is one of two spellings the framework ships. The class-name form carries no `can:`
    // alias, so before the prefix list moved into CanGate this route published no 403 whatsoever.
    $document = generateDocument()->document->toArray();

    expect($document['paths']['/api/marquees-using']['get']['responses'])->toHaveKey('403')
        ->and(gateFindings())->toHaveKey('GET /api/marquees-using')
        ->and(gateFindings()['GET /api/marquees-using']->message)->toContain('MarqueeAccess::view()');
});

it('reports the guest-reachable route whose policy method admits guests', function (): void {
    // The pair to the silence row above: same route shape, same literal `return true;`, and the only
    // difference is a first parameter Laravel will pass null to.
    expect(gateFindings())->toHaveKey('GET /api/kiosks-public-any');
});

it('stays silent on every gate once a Gate::before hook is registered', function (): void {
    // Executed rather than asserted: the hook can answer for any ability, so nothing a policy body says
    // settles the question any more — including the routes that report without it.
    $before = gateFindings();
    expect($before)->toHaveKey('GET /api/kiosks');

    Gate::before(static fn (): ?bool => null);

    expect(gateFindings())->toBe([]);
});

it('stays silent on every gate once a Gate::after hook is registered', function (): void {
    expect(gateFindings())->toHaveKey('GET /api/kiosks');

    Gate::after(static fn (): ?bool => null);

    expect(gateFindings())->toBe([]);
});

/*
 * Cache soundness. The report is a fact one route's build found, so a warm hit has to replay it, and
 * everything the decision READ has to key the fragment — the policy's own file above all, and the gate
 * registrations, which no route file reflects at all.
 */

afterEach(function (): void {
    removeFragmentCacheDirs('fragments');
});

it('reports the same gates on a warm build as on a cold one', function (): void {
    fragmentCacheDir('fragments');

    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    $cold = diagnosticRecords(generateDocument()->diagnostics);
    $engine->analyzeCount = 0;
    $warm = diagnosticRecords(generateDocument()->diagnostics);

    // Two equal builds prove nothing on their own — two COLD builds are equal too. The second one has
    // to be the cache answering, which is what a zero analyse count says.
    expect($engine->analyzeCount)->toBe(0)
        ->and($warm)->toBe($cold)
        ->and(json_encode($warm))->toContain('authorization.gate-cannot-deny');
});

/** Composer's own loader, the one thing that publishes where a class WOULD be written. */
function composerClassLoader(): ClassLoader
{
    foreach (spl_autoload_functions() as $autoloader) {
        if (is_array($autoloader) && ($autoloader[0] ?? null) instanceof ClassLoader) {
            return $autoloader[0];
        }
    }

    throw new RuntimeException('no Composer autoloader registered');
}

/**
 * Build cold, then warm, and hand back the counting engine with a warm build already proven — every
 * invalidation row below is otherwise satisfied by a cache that was never working. ONE engine through-
 * out on purpose: its class is part of the build fingerprint, so swapping it mid-test misses everything
 * for a reason that has nothing to do with what is being asserted.
 */
function gateWarmedEngine(): CountingTypeEngine
{
    fragmentCacheDir('fragments');
    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    generateDocument();
    $engine->analyzeCount = 0;
    generateDocument();

    expect($engine->analyzeCount)->toBe(0);

    return $engine;
}

it('invalidates the fragment when the policy method it read is edited', function (): void {
    // Written to a temp file, because the fact is about the fragment KEY rather than about any
    // particular policy: the file a verdict was read out of has to be one the route depends on.
    $file = sys_get_temp_dir().'/docuccino-gate-policy-'.uniqid('', true).'.php';
    // Namespaced, because the `can:` middleware tells a class argument from a route-parameter name by
    // looking for a namespace separator — a global class would reach the gate as a parameter name.
    $namespace = 'DocuccinoTempGate'.dechex(random_int(0, PHP_INT_MAX));
    $subject = $namespace.'\\Kiosk';
    $class = $namespace.'\\KioskPolicy';
    $body = 'return true;';
    $source = static fn (string $body): string => "<?php\nnamespace $namespace;\nclass Kiosk {}\nclass KioskPolicy { public function viewAny(?object \$user): bool { $body } }\n";
    file_put_contents($file, $source($body));
    require $file;

    app('router')->get('api/kiosks-temp', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('viewAny', $subject);
    app('router')->getRoutes()->refreshNameLookups();
    Gate::policy($subject, $class);

    $engine = gateWarmedEngine();
    expect(gateFindings())->toHaveKey('GET /api/kiosks-temp');

    // Tighten the policy on disk. The class is already loaded, so nothing about this build changes
    // except the file's hash — which is exactly the claim.
    file_put_contents($file, $source('return $user !== null;'));
    clearstatcache();
    touch($file, time() + 5);

    $engine->analyzeCount = 0;
    generateDocument();

    expect($engine->analyzeCount)->toBeGreaterThan(0);

    unlink($file);
});

it('invalidates the fragment when a policy appears at the name the convention looks under', function (): void {
    // The under-keyed direction: the Gate resolves a conventional policy by class_exists, so CREATING
    // one changes the verdict while changing no file the build read and no registration the digest
    // sees. Where a policy would be written is a path this records the ABSENCE of, which the cache
    // stores as a fact rather than skipping.
    $directory = sys_get_temp_dir().'/docuccino-gate-absent-'.uniqid('', true);
    mkdir($directory.'/Policies', 0o777, true);
    $namespace = 'DocuccinoAbsentGate'.dechex(random_int(0, PHP_INT_MAX));
    $model = $namespace.'\\Awning';
    file_put_contents($directory.'/Awning.php', "<?php\nnamespace $namespace;\nclass Awning {}\n");
    require $directory.'/Awning.php';
    composerClassLoader()->addPsr4($namespace.'\\', [$directory]);

    app('router')->get('api/awnings-temp', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('viewAny', $model);
    app('router')->getRoutes()->refreshNameLookups();

    $engine = gateWarmedEngine();
    expect(gateFindings())->not->toHaveKey('GET /api/awnings-temp');

    // Write the policy the convention was looking for. The class stays unloadable in THIS process —
    // Composer remembers a class it failed to find — so the claim here is only the invalidation, which
    // is the half that cannot be seen any other way.
    file_put_contents(
        $directory.'/Policies/AwningPolicy.php',
        "<?php\nnamespace $namespace\\Policies;\nclass AwningPolicy { public function viewAny(?object \$user): bool { return true; } }\n",
    );
    clearstatcache();

    $engine->analyzeCount = 0;
    generateDocument();

    expect($engine->analyzeCount)->toBeGreaterThan(0);

    unlink($directory.'/Policies/AwningPolicy.php');
    unlink($directory.'/Awning.php');
    rmdir($directory.'/Policies');
    rmdir($directory);
});

it('invalidates the fragment on the conventional name even where a policy already resolved', function (): void {
    // The same under-keying one resolution branch over. Laravel asks the name guesser BEFORE it falls
    // back to a registration on a PARENT class, so a model can resolve to its parent's policy with the
    // conventional name still absent — and writing that file then moves the gate to a different policy
    // while nothing the build read has changed. Recording the guessed names only where resolution came
    // back empty left this branch keyed on nothing.
    $directory = sys_get_temp_dir().'/docuccino-gate-parent-'.uniqid('', true);
    mkdir($directory.'/Policies', 0o777, true);
    $namespace = 'DocuccinoParentGate'.dechex(random_int(0, PHP_INT_MAX));
    file_put_contents($directory.'/Signs.php', "<?php\nnamespace $namespace;\nclass Awning {}\nclass Marquee extends Awning {}\n");
    file_put_contents(
        $directory.'/Policies/AwningPolicy.php',
        "<?php\nnamespace $namespace\\Policies;\nclass AwningPolicy { public function viewAny(?object \$user): bool { return true; } }\n",
    );
    require $directory.'/Signs.php';
    require $directory.'/Policies/AwningPolicy.php';
    composerClassLoader()->addPsr4($namespace.'\\', [$directory]);
    Gate::policy($namespace.'\\Awning', $namespace.'\\Policies\\AwningPolicy');

    app('router')->get('api/marquees-temp', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('viewAny', $namespace.'\\Marquee');
    app('router')->getRoutes()->refreshNameLookups();

    $engine = gateWarmedEngine();
    // The gate DID resolve — through the parent's registration — which is what makes this the branch
    // the recording used to skip.
    expect(gateFindings())->toHaveKey('GET /api/marquees-temp');

    file_put_contents(
        $directory.'/Policies/MarqueePolicy.php',
        "<?php\nnamespace $namespace\\Policies;\nclass MarqueePolicy { public function viewAny(?object \$user): bool { return \$user !== null; } }\n",
    );
    clearstatcache();

    $engine->analyzeCount = 0;
    generateDocument();

    expect($engine->analyzeCount)->toBeGreaterThan(0);

    unlink($directory.'/Policies/MarqueePolicy.php');
    unlink($directory.'/Policies/AwningPolicy.php');
    unlink($directory.'/Signs.php');
    rmdir($directory.'/Policies');
    rmdir($directory);
});

it('keys the fragment cache on the app\'s gate registrations', function (): void {
    // A `Gate::policy()` call, a policy-name guesser and a `Gate::before` hook all live in a service
    // provider, which no route records. Without this the first build after one was added would serve
    // every warm fragment the verdict the OLD registrations implied.
    $engine = gateWarmedEngine();

    Gate::before(static fn (): ?bool => null);
    generateDocument();

    expect($engine->analyzeCount)->toBeGreaterThan(0);
});

it('contributes the gate registrations whatever a document turns off', function (): void {
    // Gates are the framework's own authorization vocabulary, so the contributor is in the set for a
    // document with every integration disabled — the accident an integration-gated one would be.
    /** @var array<string, mixed> $raw */
    $raw = documentSettings();
    foreach (array_keys((array) ($raw['integrations'] ?? [])) as $integration) {
        $raw['integrations'][$integration]['enabled'] = false;
    }

    $extensions = DefaultExtensions::all(app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton'));

    expect($extensions)->toContain(GatePoliciesDigestContributor::class);
});

it('digests every gate registration that can change a verdict', function (): void {
    $gate = app(GateContract::class);
    $digest = static fn (): string => (new GatePoliciesDigestContributor(static fn (): GateContract => $gate))->digest();

    // A segment is "\0"-joined label/value pairs ({@see EnvironmentDigestContributor}), so a fact is
    // read back as the entry after its label.
    $reads = static function (string $digest, string $label): ?string {
        $parts = explode("\0", $digest);
        $at = array_search($label, $parts, true);

        return $at === false ? null : ($parts[$at + 1] ?? null);
    };

    $base = $digest();
    expect(explode("\0", $base))->toContain('gate-policies')
        ->and($reads($base, 'before'))->toBe('0')
        ->and($reads($base, 'after'))->toBe('0')
        ->and($reads($base, 'guesser'))->toBe('n');

    // A registration beforeEach has not already made, so the difference is this call's.
    Gate::policy(Placard::class, PlacardPolicy::class);
    $withPolicy = $digest();

    Gate::before(static fn (): ?bool => null);
    $withHook = $digest();

    Gate::guessPolicyNamesUsing(static fn (string $class): string => $class.'Policy');
    $withGuesser = $digest();

    expect($withPolicy)->not->toBe($base)
        ->and($withHook)->not->toBe($withPolicy)
        ->and($withGuesser)->not->toBe($withHook)
        ->and($reads($withGuesser, 'guesser'))->toBe('y');
});

/**
 * The policy map as an ORDER: the app's Gate carrying exactly these registrations, in exactly this
 * sequence. `Gate::policy()` cannot express that on its own — assigning a key the map already holds
 * leaves it where it was — so the map is emptied first, and the sequence is the whole input under test.
 *
 * @param  list<array{class-string, class-string}>  $registrations
 */
function gateRegisteredInOrder(array $registrations): GateContract
{
    $gate = app(GateContract::class);
    (new ReflectionProperty(IlluminateGate::class, 'policies'))->setValue($gate, []);
    foreach ($registrations as [$subject, $policy]) {
        $gate->policy($subject, $policy);
    }

    return $gate;
}

it('digests an exact registration by content rather than by the order it was registered', function (): void {
    // An exact registration is a keyed lookup, so its position cannot change what any gate resolves to.
    // A reorder that moves no resolution must move no digest either: keying the whole map as a sequence
    // would rebuild every fragment in the application over a change nothing can observe.
    //
    // Both subjects are FINAL, which is what puts them here rather than in the row below: nothing can be
    // a subclass of either, so neither is reachable by the one branch that reads the map in order.
    // Asserted, because a fixture that stopped being final would leave this row quietly testing the
    // opposite rule and still passing.
    expect((new ReflectionClass(Marquee::class))->isFinal())->toBeTrue()
        ->and((new ReflectionClass(Placard::class))->isFinal())->toBeTrue();

    $digest = static fn (GateContract $gate): string => (new GatePoliciesDigestContributor(static fn (): GateContract => $gate))->digest();

    $first = $digest(gateRegisteredInOrder([[Marquee::class, MarqueeAccess::class], [Placard::class, PlacardPolicy::class]]));
    $second = $digest(gateRegisteredInOrder([[Placard::class, PlacardPolicy::class], [Marquee::class, MarqueeAccess::class]]));

    expect($second)->toBe($first);
});

it('digests the subclass fallback as an order, because that is how a gate resolves it', function (): void {
    // Resolution's last branch walks the policy map and takes the FIRST registration the model is a
    // subclass of. So two applications that registered the same two parent types in opposite orders
    // authorize the same model with DIFFERENT policies, and a digest keying the fragment cache on the
    // resolution has to say so — or a build that reorders its registrations is served a fragment
    // computed under the other resolution.
    $digest = static fn (GateContract $gate): string => (new GatePoliciesDigestContributor(static fn (): GateContract => $gate))->digest();

    // Read while each order is in place: there is one Gate in the application, so the second
    // registration sequence replaces the first rather than standing beside it.
    $reading = static fn (GateContract $gate): array => [
        'digest' => $digest($gate),
        'framework' => $gate->getPolicyFor(Hoarding::class),
        'mirror' => GateInternals::read($gate)?->policyClassFor(Hoarding::class),
    ];

    $first = $reading(gateRegisteredInOrder([[Illuminated::class, IlluminatedPolicy::class], [Weatherproof::class, WeatherproofPolicy::class]]));
    $second = $reading(gateRegisteredInOrder([[Weatherproof::class, WeatherproofPolicy::class], [Illuminated::class, IlluminatedPolicy::class]]));

    // The premise first, and taken from the FRAMEWORK's own resolution rather than from the mirror this
    // package keys on: the same two registrations really do authorize with two different policies.
    // Without it the digest assertion would pass over a resolution that never moved.
    expect($first['framework'])->toBeInstanceOf(IlluminatedPolicy::class)
        ->and($second['framework'])->toBeInstanceOf(WeatherproofPolicy::class)
        // And the mirror agrees with it, which is what makes the digest's partition the right one.
        ->and($first['mirror'])->toBe(IlluminatedPolicy::class)
        ->and($second['mirror'])->toBe(WeatherproofPolicy::class)
        ->and($second['digest'])->not->toBe($first['digest']);
});

it('recomputes a verdict a reordered registration moved, instead of replaying it warm', function (): void {
    // The property under repair, asserted where it is owed: a warm build must equal a cold one in
    // DIAGNOSTICS as well as in bytes. Reordering two parent-type registrations moves the gate to the
    // other policy, and the two disagree about whether it can deny — so the verdict is visible in
    // whether the check reports at all. No file on disk changes, so nothing but the digest can carry it.
    app('router')->get('api/hoardings-temp', [KioskController::class, 'index'])
        ->middleware('auth:web')
        ->can('viewAny', Hoarding::class);
    app('router')->getRoutes()->refreshNameLookups();

    gateRegisteredInOrder([[Illuminated::class, IlluminatedPolicy::class], [Weatherproof::class, WeatherproofPolicy::class]]);
    $engine = gateWarmedEngine();

    // Reported, because the gate resolved to the policy whose body cannot deny.
    expect(gateFindings())->toHaveKey('GET /api/hoardings-temp');

    gateRegisteredInOrder([[Weatherproof::class, WeatherproofPolicy::class], [Illuminated::class, IlluminatedPolicy::class]]);
    $engine->analyzeCount = 0;
    $warm = diagnosticRecords(generateDocument()->diagnostics);
    // Read while the warm cache is still the one that answered: the two cold builds below analyse
    // everything, so a count taken after them is greater than zero whatever the digest did.
    $warmAnalyses = $engine->analyzeCount;

    // What the warm build owes: the same registrations, read from an empty cache.
    fragmentCacheDir('fragments');
    $coldFindings = gateFindings();
    $cold = diagnosticRecords(generateDocument()->diagnostics);

    expect($warmAnalyses)->toBeGreaterThan(0)
        // The verdict really did move — silence, now that the gate resolves to the policy that can deny
        // — so the equality below is not being asserted over a document that never changed.
        ->and($coldFindings)->not->toHaveKey('GET /api/hoardings-temp')
        ->and($warm)->toBe($cold);
});

it('says nothing about a policy method a package declares', function (): void {
    // The report's whole remedy is an edit. A policy shipped inside a package, or a vendor base class
    // an application policy extends, is a body its reader does not own — so the check answers "is there
    // a shape where this fires and nothing can be done?" with no, rather than naming somebody else's
    // file. The boundary is the directory the fixture policy really sits in, which stands in for a
    // package without one having to be installed.
    $context = gateDenialContext();
    $gate = CanGate::parse('can:viewAny,'.Kiosk::class);
    $resolver = static fn (): GateContract => app(GateContract::class);
    $policyFile = (new ReflectionClass(KioskPolicy::class))->getFileName();
    $vendor = new VendorRoutePolicy($policyFile === false ? '' : dirname($policyFile));

    expect($gate)->not->toBeNull()
        ->and((new GateDenial($resolver, $vendor->isVendorFile(...)))->undeniablePolicyMethod($context, $gate))->toBeNull()
        // Anti-vacuity: the same gate with no boundary reports, so the silence above is the boundary's
        // doing and not the fixture's.
        ->and((new GateDenial($resolver, static fn (): bool => false))->undeniablePolicyMethod($context, $gate))->toBe(KioskPolicy::class.'::viewAny');
});

it('says nothing about an ability method an application policy inherits from a vendor base class', function (): void {
    // The composition the rule is written for, and the one the row above does not hold: the application
    // owns the policy the gate resolves TO, and a package owns the base class the ability is written
    // in, so the remedy the report would name is an edit to somebody else's repository. Two directories
    // because that is the only way a boundary can tell the two halves apart — every policy fixture in
    // this suite sits in one.
    $token = 'docuccino-package-'.dechex(random_int(0, PHP_INT_MAX));
    $root = sys_get_temp_dir().'/docuccino-gate-inherit-'.uniqid('', true);
    mkdir($root.'/'.$token, 0o777, true);
    mkdir($root.'/app', 0o777, true);

    $namespace = 'DocuccinoInheritGate'.dechex(random_int(0, PHP_INT_MAX));
    file_put_contents(
        $root.'/'.$token.'/BaseSignPolicy.php',
        "<?php\nnamespace $namespace\\Vendor;\nclass BaseSignPolicy { public function viewAny(?object \$user): bool { return true; } }\n",
    );
    file_put_contents(
        $root.'/app/Sign.php',
        "<?php\nnamespace $namespace;\nclass Sign {}\nclass SignPolicy extends Vendor\\BaseSignPolicy {}\n",
    );
    require $root.'/'.$token.'/BaseSignPolicy.php';
    require $root.'/app/Sign.php';
    Gate::policy($namespace.'\\Sign', $namespace.'\\SignPolicy');

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/signs', middleware: ['auth:web', 'can:viewAny,'.$namespace.'\\Sign']),
        actionRef: new ActionRef('', KioskController::class, 'index'),
        attributes: new AttributeSet([]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', [], authMiddleware: 'auth*'),
    );
    $gate = CanGate::parse('can:viewAny,'.$namespace.'\\Sign');
    $resolver = static fn (): GateContract => app(GateContract::class);

    expect($gate)->not->toBeNull()
        ->and((new GateDenial($resolver, static fn (string $file): bool => str_contains($file, $token)))
            ->undeniablePolicyMethod($context, $gate))->toBeNull()
        // Anti-vacuity, and the mechanism in one line: with nothing vendor the same gate reports, and
        // what it names is the BASE's method — so the file the boundary is asked about is the base's.
        ->and((new GateDenial($resolver, static fn (): bool => false))->undeniablePolicyMethod($context, $gate))
        ->toBe($namespace.'\\Vendor\\BaseSignPolicy::viewAny');

    unlink($root.'/'.$token.'/BaseSignPolicy.php');
    unlink($root.'/app/Sign.php');
    rmdir($root.'/'.$token);
    rmdir($root.'/app');
    rmdir($root);
});

it('gives the check the application vendor boundary it needs', function (): void {
    // The rule above only holds where something supplies the boundary, and nothing else in the suite
    // would notice if the provider stopped.
    $boundary = (new ReflectionProperty(GateDenial::class, 'isVendorFile'))->getValue(app(GateDenial::class));

    expect($boundary)->toBeInstanceOf(Closure::class)
        ->and($boundary(base_path('vendor/acme/signage/src/Policies/SignPolicy.php')))->toBeTrue()
        ->and($boundary(base_path('app/Policies/SignPolicy.php')))->toBeFalse();
});

it('builds, and stays silent, for an application with no Gate bound', function (): void {
    // The check reaches the container for a Gate, and an app that replaced the framework auth providers
    // has none. Resolving that eagerly turned a working build into a thrown exception, since the
    // extension registry does not catch what a constructor raises.
    unset(app()[GateContract::class]);

    expect(static fn () => app(GateContract::class))->toThrow(BindingResolutionException::class)
        ->and(gateFindings())->toBe([])
        // The 403 itself is not the check's to withhold: it publishes from the gate's presence.
        ->and(generateDocument()->document->toArray()['paths']['/api/kiosks']['get']['responses'])->toHaveKey('403');
});

it('stays silent when the application own policy-name guesser throws', function (): void {
    // A guesser is arbitrary application code, and it is the one piece of it a build runs. Whatever it
    // does, the answer is a gate this could not resolve.
    expect(gateFindings())->toHaveKey('GET /api/kiosks');

    Gate::guessPolicyNamesUsing(static fn (string $class): string => throw new RuntimeException('nope'));
    $after = gateFindings();

    expect($after)->not->toHaveKey('GET /api/kiosks')
        // Anti-vacuity: a policy the app registered explicitly is answered before the guesser is asked,
        // so the silence above is about the throw and not about the build having stopped working.
        ->and($after)->toHaveKey('GET /api/marquees');
});

it('stays silent when the Gate resolution step it calls throws', function (): void {
    // The other half of the resolution an application can reach into: the `#[UsePolicy]` step, which the
    // Gate keeps protected and this therefore invokes by reflection. What provokes the raise is the
    // MODEL — {@see Fascia} repeats an attribute PHP refuses to instantiate — so the throw comes out of
    // the framework's own method, and what it raises has to answer "a gate this could not resolve" and
    // never reach the build.
    $gate = new IlluminateGate(app(), static fn () => null);
    $denial = new GateDenial(static fn (): GateContract => $gate, static fn (): bool => false);
    $context = gateDenialContext();
    $raises = CanGate::parse('can:viewAny,'.Fascia::class);
    $reads = CanGate::parse('can:viewAny,'.Pylon::class);

    expect($raises)->not->toBeNull()
        ->and($denial->undeniablePolicyMethod($context, $raises))->toBeNull()
        // Anti-vacuity, in the two ways this row can go vacuous. The conventional policy the resolution
        // falls through to is really there, and really named, so the silence is the raise rather than a
        // model nothing answers for…
        ->and(GateInternals::read($gate)?->guessedNames(Fascia::class))->toContain(FasciaPolicy::class)
        ->and(class_exists(FasciaPolicy::class))->toBeTrue()
        // …and the step that raised is really on the path: the same gate resolves a model whose attribute
        // CAN be read to the policy that attribute names, which no convention here would find.
        ->and($denial->undeniablePolicyMethod($context, $reads))->toBe(PylonAccess::class.'::viewAny');
});

it('says nothing it cannot read, rather than guessing', function (): void {
    // A Gate implementation this cannot reflect contributes the empty string and counts as one that HAS
    // hooks, so the check degrades to silence instead of to a confident claim about someone else's Gate.
    $foreign = new class implements GateContract
    {
        public function has($ability): bool
        {
            return false;
        }

        public function define($ability, $callback): self
        {
            return $this;
        }

        public function resource($name, $class, ?array $abilities = null): self
        {
            return $this;
        }

        public function policy($class, $policy): self
        {
            return $this;
        }

        public function before(callable $callback): self
        {
            return $this;
        }

        public function after(callable $callback): self
        {
            return $this;
        }

        public function allows($ability, $arguments = []): bool
        {
            return true;
        }

        public function denies($ability, $arguments = []): bool
        {
            return false;
        }

        public function check($abilities, $arguments = []): bool
        {
            return true;
        }

        public function any($abilities, $arguments = []): bool
        {
            return true;
        }

        public function authorize($ability, $arguments = []): Response
        {
            return Response::allow();
        }

        public function inspect($ability, $arguments = []): Response
        {
            return Response::allow();
        }

        public function raw($ability, $arguments = []): bool
        {
            return true;
        }

        public function getPolicyFor($class): ?object
        {
            return new KioskPolicy;
        }

        public function forUser($user): self
        {
            return $this;
        }

        public function abilities(): array
        {
            return [];
        }
    };

    $context = gateDenialContext();
    $gate = CanGate::parse('can:viewAny,'.Kiosk::class);

    expect($gate)->not->toBeNull()
        ->and((new GateDenial(static fn (): GateContract => $foreign, static fn (): bool => false))->undeniablePolicyMethod($context, $gate))->toBeNull()
        ->and((new GatePoliciesDigestContributor(static fn (): GateContract => $foreign))->digest())->toBe('')
        // Anti-vacuity: the real Gate answers for the very same context, so the null above is about the
        // Gate this cannot read and not about the fixture.
        ->and((new GateDenial(static fn (): GateContract => app(GateContract::class), static fn (): bool => false))->undeniablePolicyMethod($context, $gate))
        ->toBe(KioskPolicy::class.'::viewAny');
});

it('keys the fragment on every file a policy-naming attribute could be written into', function (): void {
    // The `#[UsePolicy]` branches read a model's attributes, and Laravel 13's walks the PARENTS as well
    // — so a base model is a file that decides which policy the gate resolves to. Keying only on the
    // model's own file leaves a warm build replaying the verdict from before the attribute was added,
    // and no route file reflects the edit either.
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/lightboxes', middleware: ['auth:web', 'can:viewAny,'.Lightbox::class]),
        actionRef: new ActionRef('', KioskController::class, 'index'),
        attributes: new AttributeSet([]),
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', [], authMiddleware: 'auth*'),
    );

    $gate = CanGate::parse('can:viewAny,'.Lightbox::class);
    expect($gate)->not->toBeNull();

    (new GateDenial(static fn (): GateContract => app(GateContract::class), static fn (): bool => false))
        ->undeniablePolicyMethod($context, $gate);

    $files = $context->dependencies()->files();

    // Recorded on every version, because where a fact can be WRITTEN is not a function of which
    // framework happens to read it — and the row is the same on both, so it cannot quietly stop
    // proving anything on the older one.
    expect($files)->toContain((new ReflectionClass(Lightbox::class))->getFileName())
        ->and($files)->toContain((new ReflectionClass(Totem::class))->getFileName());
});
