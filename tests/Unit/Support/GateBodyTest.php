<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Support\GateBody;

/**
 * The one seam three producers of the implicit 403 ask "could this gate ever refuse?" through. Its
 * whole point is that {@see GateBody::Unread} is an ANSWER — the callers want opposite defaults for it
 * — so every row here is about which of the three comes back, never about a boolean.
 *
 * The bodies are written to a temp file rather than to a fixture class, because two of them are about
 * the FILE: one that will not parse at all, and one holding two classes that declare a method of the
 * same name.
 */
function gateBodyFile(): string
{
    static $file = null;

    if ($file === null) {
        $file = sys_get_temp_dir().'/docuccino-gate-bodies-'.uniqid('', true).'.php';
        $namespace = 'DocuccinoGateBodies'.dechex(random_int(0, PHP_INT_MAX));
        file_put_contents($file, <<<PHP
            <?php
            namespace $namespace;
            class Bodies {
                public function allows(?object \$u): bool { return true; }
                public function denies(?object \$u): bool { return \$u !== null; }
                public function branches(?object \$u): bool { if (\$u !== null) { return true; } return false; }
                public function twin(?object \$u): bool { return true; }
            }
            class Shadow {
                public function twin(?object \$u): bool { return true; }
            }
            PHP);
        require $file;
        $GLOBALS['docuccinoGateBodiesNamespace'] = $namespace;
    }

    return $file;
}

function gateBodyContext(?TypeEngine $engine = null): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/probe'),
        actionRef: new ActionRef('', null, 'index'),
        attributes: new AttributeSet([]),
        engine: $engine ?? new NullTypeEngine,
        document: new DocumentConfig('default', []),
    );
}

function gateBodyRef(ReflectionMethod $method): ActionRef
{
    return new ActionRef(gateBodyFile(), $method->getDeclaringClass()->getName(), $method->getName(), $method->getStartLine());
}

function gateBodyMethod(string $class, string $method): ReflectionMethod
{
    gateBodyFile();

    return new ReflectionMethod($GLOBALS['docuccinoGateBodiesNamespace'].'\\'.$class, $method);
}

it('reads a body off the source when no analyser answered', function (string $class, string $method, GateBody $expected): void {
    $reflected = gateBodyMethod($class, $method);

    expect(GateBody::read(gateBodyContext(), $reflected, gateBodyFile()))->toBe($expected);
})->with([
    'a literal return true' => ['Bodies', 'allows', GateBody::AlwaysAllows],
    'anything read at all' => ['Bodies', 'denies', GateBody::CanDeny],
    'true reached conditionally' => ['Bodies', 'branches', GateBody::CanDeny],
    // Two classes in one file declaring one method name: the parse keys by name, so the line is what
    // ties a node to the reflection found, and a body it cannot tie is one nobody read.
    'a method name a second class in the file also declares' => ['Bodies', 'twin', GateBody::Unread],
    // The same reflection against the class the parse DID keep, so the row above is about the tie-break
    // and not about the method.
    'the declaration the name resolves to' => ['Shadow', 'twin', GateBody::AlwaysAllows],
]);

it('reads a file it cannot open as a body nobody read', function (): void {
    $reflected = gateBodyMethod('Bodies', 'allows');

    expect(GateBody::read(gateBodyContext(), $reflected, gateBodyFile().'.missing'))->toBe(GateBody::Unread);
});

it('reaches past the source read with what the analyser proved', function (): void {
    // What the narrow read cannot see: two return statements, both of them `true`. The engine can, and
    // its answer is the one that widens what the check finds.
    $reflected = gateBodyMethod('Bodies', 'branches');
    $engine = new StubTypeEngine([gateBodyRef($reflected)->symbol() => new ActionAnalysis(returns: [
        new ReturnSite(new LiteralT(true), new SourceLocation(gateBodyFile(), 1)),
        new ReturnSite(new LiteralT(true), new SourceLocation(gateBodyFile(), 2)),
    ])]);

    expect(GateBody::read(gateBodyContext(), $reflected, gateBodyFile()))->toBe(GateBody::CanDeny)
        ->and(GateBody::read(gateBodyContext($engine), $reflected, gateBodyFile()))->toBe(GateBody::AlwaysAllows);
});

it('keeps the certain answer when the analyser contradicts it', function (): void {
    // The row that holds the ordering rule stated on GateBody::read(): an analyser having a bad day
    // must not be able to turn the check off.
    $reflected = gateBodyMethod('Bodies', 'allows');
    $engine = new StubTypeEngine([gateBodyRef($reflected)->symbol() => new ActionAnalysis(returns: [
        new ReturnSite(new UnknownT('analysis failed'), new SourceLocation(gateBodyFile(), 1)),
    ])]);

    expect(GateBody::read(gateBodyContext($engine), $reflected, gateBodyFile()))->toBe(GateBody::AlwaysAllows)
        // …while the engine-only entry point does read it that way, which is the whole of the difference
        // between the two callers: a FormRequest gate the analyser could not read publishes no 403, and
        // a `can:` gate it could not read keeps the one it has.
        ->and(GateBody::analysed(gateBodyContext($engine), $reflected, gateBodyFile()))->toBe(GateBody::CanDeny)
        ->and(GateBody::analysed(gateBodyContext(), $reflected, gateBodyFile()))->toBe(GateBody::Unread);
});

it('records what the analyser read as a dependency of the route', function (): void {
    $reflected = gateBodyMethod('Bodies', 'allows');
    $context = gateBodyContext();

    GateBody::analysed($context, $reflected, gateBodyFile());

    expect($context->dependencyFiles())->toContain(gateBodyFile());
});
