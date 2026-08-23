<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Blank;
use Docuccino\Laravel\Tests\Fixtures\RouteBindings\BindingController;
use Docuccino\Laravel\Tests\Fixtures\RouteBindings\Channel;
use Docuccino\Laravel\Tests\Fixtures\RouteBindings\Ticket;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Enums\WidgetPriority;

/**
 * Laravel's implicit binding resolves a STRING-backed enum from the path segment (`tryFrom` on the
 * backing value, 404 on a miss), so a string-backed-enum hint's value domain is the enum's cases
 * exactly — never the integer guess a non-model binding used to get. Nothing else on a signature
 * binds: an int-backed enum, a pure enum, a custom `UrlRoutable` and — with the Eloquent integration
 * off — a model are all honest strings, each with a diagnostic saying the parameter went untyped.
 */
beforeEach(function (): void {
    $this->boundDocument = static function (callable $routes, ?callable $mutateConfig = null): array {
        $routes(app('router'));
        app()->instance(TypeEngine::class, WorkbenchEngine::make());

        $result = generateDocument($mutateConfig);

        return [$result->document->toArray(), $result->diagnostics];
    };
});

it('types a string-backed-enum bound parameter from the enum', function (string $uri, string $action, string $component, string $type, array $values): void {
    [$document, $diagnostics] = ($this->boundDocument)(static function (Router $router) use ($uri, $action): void {
        $router->get($uri, [BindingController::class, $action]);
    });

    $parameter = pathParameter($document['paths']['/'.$uri]['get'], $action);
    $schema = $document['components']['schemas'][$component] ?? null;

    expect($parameter)->not->toBeNull()
        ->and($parameter['schema']['$ref'] ?? null)->toBe('#/components/schemas/'.$component)
        ->and($schema)->not->toBeNull()
        ->and($schema['type'])->toBe($type)
        ->and($schema['enum'])->toBe($values)
        ->and(diagnosticsCoded($diagnostics, 'route-binding.untyped'))->toBeEmpty();
})->with([
    'string-backed enum' => [
        'api/zz-enum-status/{status}', 'status', 'WidgetStatus', 'string', ['draft', 'published', 'archived'],
    ],
]);

it('documents an unbindable hint as a plain string, and says why', function (string $uri, string $action, string $className, ?callable $mutateConfig = null): void {
    [$document, $diagnostics] = ($this->boundDocument)(static function (Router $router) use ($uri, $action): void {
        $router->get($uri, [BindingController::class, $action]);
    }, $mutateConfig);

    $parameter = pathParameter($document['paths']['/'.$uri]['get'], $action);
    $signature = 'GET /'.$uri;
    $reports = array_values(array_filter(
        diagnosticsCoded($diagnostics, 'route-binding.untyped'),
        static fn ($diagnostic): bool => $diagnostic->routeSignature === $signature,
    ));

    expect($parameter)->not->toBeNull()
        ->and($parameter['schema']['type'])->toBe('string')
        ->and($parameter['schema'])->not->toHaveKey('enum')
        ->and($parameter['schema'])->not->toHaveKey('format')
        ->and($parameter['schema']['x-docuccino']['provenance'][0]['producer'])->toBe('fallback')
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]->severity->value)->toBe('info')
        ->and($reports[0]->message)->toBe(sprintf(
            '{%s} is bound to %s, which nothing enabled could type, so the parameter is documented as a plain string.',
            $action,
            $className,
        ));
})->with([
    // Laravel's Reflector binds a backed enum only when its backing type is `string`, so an int-backed
    // hint never reaches `tryFrom`: typing the parameter as the enum would promise a wire contract no
    // request can satisfy.
    'an int-backed enum' => [
        'api/zz-enum-priority/{priority}', 'priority', WidgetPriority::class,
    ],
    // A pure enum has no backing value to match a segment against at all.
    'a pure enum' => [
        'api/zz-enum-channel/{channel}', 'channel', Channel::class,
    ],
    // A custom UrlRoutable keys on whatever its resolveRouteBinding says — statically unknowable.
    'a UrlRoutable class' => [
        'api/zz-ticket-keys/{ticket}', 'ticket', Ticket::class,
    ],
    // A real Eloquent model, with the integration that types route keys switched off: the binding is
    // just as untyped, so the message must not blame the class for what the config withheld.
    'a model with the eloquent integration disabled' => [
        'api/zz-enum-blanks/{blank}',
        'blank',
        Blank::class,
        static function (array $raw): array {
            $raw['integrations']['eloquent']['enabled'] = false;

            return $raw;
        },
    ],
]);

it('leaves model bindings on the route-key schema, unreported', function (): void {
    [$document, $diagnostics] = ($this->boundDocument)(static function (Router $router): void {
        $router->get('api/zz-enum-blanks/{blank}', [BindingController::class, 'blank']);
    });

    $parameter = pathParameter($document['paths']['/api/zz-enum-blanks/{blank}']['get'], 'blank');

    expect($parameter['schema']['type'])->toBe('integer')
        ->and(diagnosticsCoded($diagnostics, 'route-binding.untyped'))->toBeEmpty();
});
