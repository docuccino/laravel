<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Workbench\App\Http\Controllers\FormController;

/**
 * The operationId representation policy (design §Representation policies) chooses how the default
 * operationId is derived; #[OperationId] still overrides it.
 */
beforeEach(function (): void {
    $this->representationDocument = static function (?string $strategy = null): array {
        app()->instance(TypeEngine::class, WorkbenchEngine::make());

        /** @var array<string, mixed> $raw */
        $raw = documentSettings();
        if ($strategy !== null) {
            $raw['representation']['operation_id'] = $strategy;
        }

        $config = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');

        return app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document->toArray();
    };
});

it('derives controller-method operationIds when the policy asks for it', function (): void {
    $document = ($this->representationDocument)('controller-method');

    expect($document['paths']['/api/forms']['get']['operationId'])->toBe('FormController@index')
        ->and($document['paths']['/api/forms/{form}']['get']['operationId'])->toBe('FormController@show');
});

/**
 * An `operationId` is what a client generator names a method after, so publishing none does not leave
 * the consumer without a name — it leaves them with whatever their generator invents from the path,
 * which differs between generators and is deduplicated inside them in encounter order. The route-name
 * strategy has no name to read on an unnamed route, and an unnamed route is the ordinary case in a
 * Laravel application: not one of the workbench's routes is named, so the whole document used to
 * publish no ids at all. The default now answers from the operation itself.
 */
it('names an operation the route-name strategy has no name for', function (): void {
    $document = ($this->representationDocument)();

    expect($document['paths']['/api/forms']['get']['operationId'])->toBe('get.api.forms')
        ->and($document['paths']['/api/forms/{form}']['get']['operationId'])->toBe('get.api.forms.@form')
        // The closure route too: it has no class for the controller-method strategy to read either,
        // and its method and path say as much about it as any other route's do.
        ->and($document['paths']['/api/ping']['get']['operationId'])->toBe('get.api.ping');
});

/** Every operation the document publishes carries one, and no two carry the same one. */
it('names every operation exactly once', function (): void {
    $document = ($this->representationDocument)();

    $operations = [];
    $ids = [];
    foreach ($document['paths'] as $path => $item) {
        foreach ($item as $method => $operation) {
            $operations[] = strtoupper((string) $method).' '.$path;
            if (isset($operation['operationId'])) {
                $ids[] = $operation['operationId'];
            }
        }
    }

    expect($operations)->toHaveCount(31)
        ->and($ids)->toHaveCount(31)
        ->and(array_unique($ids))->toHaveCount(31);
});

/**
 * The controller-method strategy has the same hole in the other place: a closure route has no class,
 * and it used to fall back to the route name, which an unnamed closure route has not got either.
 */
it('names a closure route under the controller-method strategy too', function (): void {
    expect(($this->representationDocument)('controller-method')['paths']['/api/ping']['get']['operationId'])
        ->toBe('get.api.ping');
});

/**
 * The name the AUTHOR wrote still wins over the one we work out — a route name is their own word for
 * the operation, and the mint only stands in where there is no such word.
 */
it('prefers the route name where the route has one', function (): void {
    app('router')->get('api/named-forms', [FormController::class, 'index'])->name('forms.index');

    expect(($this->representationDocument)()['paths']['/api/named-forms']['get']['operationId'])
        ->toBe('forms.index');
});

/**
 * Routes are added here rather than reasoned about. A published name must be a function of the thing
 * and never of the set it was met in, so a route arriving next door cannot move one — which is what
 * a name settled by a CONTEST between claimants would do, since every claimant moves when one more
 * joins, and what a first-come `_2` tail would do in encounter order.
 */
it('leaves the names of the operations it already had where they were', function (): void {
    $before = ($this->representationDocument)()['paths']['/api/forms']['get']['operationId'];

    // Both point at the action /api/forms already uses, and one sorts ahead of every existing route.
    app('router')->get('api/forms-annex', [FormController::class, 'index']);
    app('router')->get('api/aaa-first', [FormController::class, 'index']);

    $after = ($this->representationDocument)();

    expect($after['paths']['/api/forms']['get']['operationId'])->toBe($before)
        ->and($after['paths']['/api/forms-annex']['get']['operationId'])->toBe('get.api.forms-annex')
        ->and($after['paths']['/api/aaa-first']['get']['operationId'])->toBe('get.api.aaa-first');
});
