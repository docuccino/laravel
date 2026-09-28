<?php

declare(strict_types=1);

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallCondition;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\InferredHandler\LocatedCallable;
use Docuccino\Laravel\Integrations\InferredHandler\RespondCallback;
use Docuccino\Laravel\Integrations\InferredHandler\RespondConditions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Which returns of a `respond()` callback a route reaches. Each row states what Laravel itself answers for
 * a request to the route — worked out by building a real `Request` against a concrete path wherever one
 * exists — so the table is checked against the framework's reading and not against its own. An undecided
 * answer keeps the return reachable either way, which is what makes it safe.
 */
function respondConditionsFor(string $uri, ?string $name = null): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['GET'], $uri, $name),
        actionRef: new ActionRef('app/Http/FormController.php', 'App\\Http\\FormController', 'show'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
    );
}

function respondConditionsCallback(): RespondCallback
{
    return new RespondCallback(new LocatedCallable('bootstrap/app.php', 1), 'response', 'e', 'request');
}

it('reads Request::is() against the route path the way Laravel does', function (string $uri, array $patterns, array $concrete, ?bool $answer): void {
    // Each concrete path is one the route really serves, and Laravel's own answer for each is the row's
    // answer: every path agreeing where the row is settled, the paths parting ways where it is not.
    $route = new Route(['GET'], $uri, static fn () => null);
    $answers = [];
    foreach ($concrete as $path) {
        expect($route->matches(Request::create($path)))->toBeTrue();
        $answers[] = Request::create($path)->is(...$patterns);
    }
    if ($answer !== null) {
        expect(array_unique($answers))->toBe($concrete === [] ? [] : [$answer]);
    } elseif (count($concrete) > 1) {
        expect(array_values(array_unique($answers)))->toHaveCount(2);
    }

    $context = respondConditionsFor($uri);
    $reachesWhenTrue = RespondConditions::reachable([new CallCondition('request', 'is', $patterns, true)], respondConditionsCallback(), $context, new ResponseDraft('404'));
    $reachesWhenFalse = RespondConditions::reachable([new CallCondition('request', 'is', $patterns, false)], respondConditionsCallback(), $context, new ResponseDraft('404'));

    // Settled: exactly one of the two branches is reached. Unsettled: both are.
    expect([$reachesWhenTrue, $reachesWhenFalse])->toBe(match ($answer) {
        true => [true, false],
        false => [false, true],
        null => [true, true],
    });
})->with([
    'a fixed path matched exactly' => ['/api/health', ['api/health'], ['/api/health'], true],
    'a fixed path a prefix pattern covers' => ['/api/health', ['api/*'], ['/api/health'], true],
    'a fixed path another prefix refuses' => ['/api/health', ['admin/*'], ['/api/health'], false],
    'the root path' => ['/', ['/'], ['/'], true],
    'a parameter behind a prefix pattern' => ['/api/forms/{form}', ['api/*'], ['/api/forms/7'], true],
    'a parameter behind a prefix that parts ways' => ['/api/forms/{form}', ['admin/*'], ['/api/forms/7'], false],
    'a parameter a pattern needs to see past' => ['/api/forms/{form}', ['api/forms/*/edit'], [], null],
    'a pattern shorter than the fixed part, no wildcard' => ['/api/forms/{form}', ['api'], [], null],
    'a template starting with a parameter' => ['/{tenant}/forms', ['api/*'], ['/api/forms', '/web/forms'], null],
    'any pattern of several matching' => ['/api/forms/{form}', ['admin/*', 'api/*'], ['/api/forms/7'], true],
    'every pattern of several refusing' => ['/api/forms/{form}', ['admin/*', 'web/*'], ['/api/forms/7'], false],
    'one refusing, one undecided' => ['/api/forms/{form}', ['admin/*', 'api/forms/*/edit'], [], null],
    // An optional parameter can be left out, and the path it leaves is one the route serves too.
    'an optional parameter a pattern needs present' => ['/api/forms/{form?}', ['api/forms/*'], ['/api/forms', '/api/forms/7'], null],
    'an optional parameter as the only varying segment' => ['/api/{form?}', ['api/*'], ['/api', '/api/7'], null],
    'an optional parameter under a prefix both forms match' => ['/api/forms/{form?}', ['api/*'], ['/api/forms', '/api/forms/7'], true],
    'an optional parameter both forms refuse' => ['/api/forms/{form?}', ['admin/*'], ['/api/forms', '/api/forms/7'], false],
    'an optional parameter each form of which one pattern matches' => ['/api/forms/{form?}', ['api/forms', 'api/forms/*'], ['/api/forms', '/api/forms/7'], true],
    'an optional parameter after a separator other than a slash' => ['/api/forms.{format?}', ['api/forms.*'], ['/api/forms', '/api/forms.json'], null],
    'two optional parameters' => ['/api/forms/{form?}/{field?}', ['api/forms/*'], ['/api/forms', '/api/forms/7', '/api/forms/7/name'], null],
]);

it('reads Request::routeIs() against the route name the way Laravel does', function (?string $name, array $patterns, bool $answer): void {
    $route = new Route(['GET'], 'api/forms', static fn () => null);
    if ($name !== null) {
        $route->name($name);
    }
    $request = Request::create('/api/forms');
    $request->setRouteResolver(static fn (): Route => $route);
    expect($request->routeIs(...$patterns))->toBe($answer);

    $context = respondConditionsFor('/api/forms', $name);

    expect(RespondConditions::reachable([new CallCondition('request', 'routeIs', $patterns, $answer)], respondConditionsCallback(), $context, new ResponseDraft('404')))->toBeTrue()
        ->and(RespondConditions::reachable([new CallCondition('request', 'routeIs', $patterns, ! $answer)], respondConditionsCallback(), $context, new ResponseDraft('404')))->toBeFalse();
})->with([
    'a matching name' => ['api.forms.index', ['api.*'], true],
    'a refusing name' => ['api.forms.index', ['admin.*'], false],
    'an unnamed route' => [null, ['api.*'], false],
]);

it('reads the rendered response status against the status it was rendered at, and only a status that was read', function (ResponseDraft $rendered, ?int $reading, bool $reachable): void {
    $condition = new CallCondition('response', 'getStatusCode', [], 419);

    // One reading of the rendered status, which the finalizer also hands a rewrite that states none of its
    // own: a branch cannot be settled on a status that a rewrite would not be filed at.
    expect(RespondConditions::renderedStatus($rendered))->toBe($reading)
        ->and(RespondConditions::reachable([$condition], respondConditionsCallback(), respondConditionsFor('/api/forms'), $rendered))->toBe($reachable);
})->with([
    'the status it was rendered at' => [new ResponseDraft('419'), 419, true],
    'another status' => [new ResponseDraft('404'), 404, false],
    'a status range, which names no one status' => [new ResponseDraft('4XX'), null, true],
    // Filed under a key nothing read (`FrameworkExceptionTable::UNPLACED_STATUS`): the server may send 419.
    'a stand-in status, which is no reading' => [(static function (): ResponseDraft {
        $draft = new ResponseDraft('500');
        $draft->recordStatusPlacement(true);

        return $draft;
    })(), null, true],
]);

it('treats a call it cannot answer without a request as no evidence either way', function (CallCondition $condition): void {
    expect(RespondConditions::reachable([$condition], respondConditionsCallback(), respondConditionsFor('/api/forms'), new ResponseDraft('404')))->toBeTrue();
})->with([
    'a header-dependent request call' => [new CallCondition('request', 'expectsJson', [], false)],
    'a call on a parameter that is neither' => [new CallCondition('e', 'isFatal', [], true)],
    'a response call other than the status' => [new CallCondition('response', 'isRedirect', [], true)],
    'the status read with arguments' => [new CallCondition('response', 'getStatusCode', ['x'], 419)],
]);
