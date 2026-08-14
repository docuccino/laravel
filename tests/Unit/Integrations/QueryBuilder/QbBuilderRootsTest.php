<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionRef;
use Docuccino\Laravel\Integrations\QueryBuilder\QbBuilderRoots;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\GadgetListQuery;
use Docuccino\Laravel\Tests\Fixtures\QueryBuilder\InjectedGadgetController;

/**
 * Which actions get a second trace root, over every parameter shape reflection can hand back. The rule is
 * deliberately narrow — a `Spatie\QueryBuilder\QueryBuilder` subclass that declares a constructor of its
 * own — so an action is never opened up beyond the builder it is handed.
 */
function rootSymbols(string $method): array
{
    $roots = QbBuilderRoots::forAction(new ActionRef(
        (string) (new ReflectionClass(InjectedGadgetController::class))->getFileName(),
        InjectedGadgetController::class,
        $method,
    ));

    return array_map(static fn (ActionRef $root): string => $root->symbol(), $roots);
}

it('seeds a root per injected builder subclass, and nothing else', function (string $method, array $symbols): void {
    expect(rootSymbols($method))->toBe($symbols);
})->with([
    'self-configuring subclass' => ['index', [GadgetListQuery::class.'::__construct']],
    // Deduped: the same constructor traced twice would harvest its allow-lists twice.
    'the same subclass twice' => ['pair', [GadgetListQuery::class.'::__construct']],
    // The package's own constructor configures nothing this documents.
    'subclass with no constructor of its own' => ['bare', []],
    // A container-injected service and a scalar are not an invitation to trace anything.
    'request + scalar parameters' => ['requestOnly', []],
    'no parameters at all' => ['noParameters', []],
]);

it('points the root at the constructor, in the file that declares it', function (): void {
    $roots = QbBuilderRoots::forAction(new ActionRef('', InjectedGadgetController::class, 'index'));

    expect($roots)->toHaveCount(1)
        ->and($roots[0]->class)->toBe(GadgetListQuery::class)
        ->and($roots[0]->method)->toBe('__construct')
        ->and($roots[0]->file)->toBe((new ReflectionClass(GadgetListQuery::class))->getFileName())
        ->and($roots[0]->line)->toBeGreaterThan(0);
});

it('seeds nothing when the action cannot be reflected', function (?string $class, string $method): void {
    expect(QbBuilderRoots::forAction(new ActionRef('routes/api.php', $class, $method)))->toBe([]);
})->with([
    // A closure route has no class to reflect.
    'closure route' => [null, '{closure}'],
    'unknown class' => ['App\\Nope\\MissingController', 'index'],
    'unknown method' => [InjectedGadgetController::class, 'missing'],
]);
