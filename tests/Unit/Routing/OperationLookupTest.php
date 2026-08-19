<?php

declare(strict_types=1);

use Docuccino\Laravel\Routing\OperationLookup;
use Docuccino\Laravel\Routing\OperationMatch;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\FormController;

/**
 * How a reader names an endpoint. Method + URI is the primary spelling because it is what the viewer
 * and the exported artifact both show; a route name is accepted but never required, since the routes
 * that document worst are disproportionately the unnamed ones.
 */
beforeEach(function (): void {
    $this->document = [
        'paths' => [
            '/api/invoices' => [
                'get' => ['operationId' => 'listInvoices'],
                'post' => ['operationId' => 'storeInvoice'],
            ],
            '/api/invoices/{invoice}' => [
                'get' => ['operationId' => 'showInvoice'],
            ],
            '/api/credit-notes' => [
                'get' => [],
                'x-vendor' => ['not' => 'an operation'],
            ],
        ],
    ];

    /** @var Router $router */
    $router = app('router');
    $router->get('api/invoices', [FormController::class, 'index'])->name('invoices.index');
    $router->post('api/invoices', [FormController::class, 'index']);

    $this->lookup = new OperationLookup($router);
});

it('lists every operation the document publishes, ordered by path then method', function (): void {
    $operations = $this->lookup->operations('default', $this->document);

    expect(array_map(static fn (OperationMatch $o): string => $o->signature(), $operations))->toBe([
        'GET /api/credit-notes',
        'GET /api/invoices',
        'POST /api/invoices',
        'GET /api/invoices/{invoice}',
    ]);
});

it('joins on what the router still knows about the route behind an operation', function (): void {
    $operations = $this->lookup->operations('default', $this->document);

    expect($operations[1]->name)->toBe('invoices.index')
        ->and($operations[1]->action)->toBe(FormController::class.'@index')
        ->and($operations[1]->shortAction())->toBe('FormController@index')
        // A route the document publishes but the router does not answer for keeps its own names only.
        ->and($operations[0]->name)->toBeNull()
        ->and($operations[0]->action)->toBeNull()
        ->and($operations[0]->shortAction())->toBeNull();
});

it('finds the one operation a spelling names', function (string $query, string $expected): void {
    $matches = $this->lookup->match($this->lookup->operations('default', $this->document), $query);

    expect(array_map(static fn (OperationMatch $o): string => $o->signature(), $matches))->toBe([$expected]);
})->with([
    'a route name' => ['invoices.index', 'GET /api/invoices'],
    'an operation id' => ['storeInvoice', 'POST /api/invoices'],
    'method and URI, as a diagnostic writes it' => ['POST /api/invoices', 'POST /api/invoices'],
    'method and URI with no leading slash' => ['post api/invoices', 'POST /api/invoices'],
    'a URI only one verb answers' => ['/api/invoices/{invoice}', 'GET /api/invoices/{invoice}'],
    'a URI with the base path left off' => ['invoices/{invoice}', 'GET /api/invoices/{invoice}'],
    'a URI with a base path the reader added' => ['/api/api/credit-notes', 'GET /api/credit-notes'],
    'a fragment only one operation carries' => ['credit', 'GET /api/credit-notes'],
]);

it('narrows a URI several verbs answer', function (?string $method, array $expected): void {
    $matches = $this->lookup->match($this->lookup->operations('default', $this->document), 'api/invoices', $method);

    expect(array_map(static fn (OperationMatch $o): string => $o->signature(), $matches))->toBe($expected);
})->with([
    'no filter leaves both' => [null, ['GET /api/invoices', 'POST /api/invoices']],
    'get' => ['get', ['GET /api/invoices']],
    'post' => ['post', ['POST /api/invoices']],
    'a verb nothing answers' => ['put', []],
]);

it('prefers an exact spelling to every operation that merely contains it', function (): void {
    // `/api/invoices` is a prefix of `/api/invoices/{invoice}`, so a substring pass would return both.
    $matches = $this->lookup->match($this->lookup->operations('default', $this->document), 'api/invoices');

    expect(array_map(static fn (OperationMatch $o): string => $o->signature(), $matches))
        ->toBe(['GET /api/invoices', 'POST /api/invoices']);
});

it('turns a fragment nothing matches exactly into every operation carrying it', function (): void {
    $matches = $this->lookup->match($this->lookup->operations('default', $this->document), 'invoice');

    expect(array_map(static fn (OperationMatch $o): string => $o->signature(), $matches))->toBe([
        'GET /api/invoices',
        'POST /api/invoices',
        'GET /api/invoices/{invoice}',
    ]);
});

it('matches nothing rather than guessing', function (string $query): void {
    expect($this->lookup->match($this->lookup->operations('default', $this->document), $query))->toBe([]);
})->with([
    'a name no route carries' => ['orders.index'],
    'an empty query' => [''],
    'a verb the URI does not answer' => ['delete api/invoices'],
]);

it('says nothing about the route behind two operations that share a method and a path', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->domain('admin.example.com')->group(static function () use ($router): void {
        $router->get('api/credit-notes', [FormController::class, 'index'])->name('admin.credits');
    });
    $router->domain('tenant.example.com')->group(static function () use ($router): void {
        $router->get('api/credit-notes', [FormController::class, 'show'])->name('tenant.credits');
    });

    $operations = (new OperationLookup($router))->operations('default', $this->document);

    expect($operations[0]->signature())->toBe('GET /api/credit-notes')
        ->and($operations[0]->name)->toBeNull()
        ->and($operations[0]->action)->toBeNull();
});
