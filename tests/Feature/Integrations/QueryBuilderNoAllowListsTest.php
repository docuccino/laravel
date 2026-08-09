<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Tests\Support\QbTraceScript;

/**
 * The silence kill: when a paginating QB terminal is reached but NO allow-list entry is recovered
 * (the descent could not follow the filter chain), an info `query-builder.no-allowlists-recovered`
 * names the action so the loss is never silent. It is suppressed the moment any entry is recovered
 * (or attempted-but-unresolved).
 */
function noAllowListDiagnostics(string $chain): array
{
    $engine = new StubTypeEngine(traces: [
        'App\\Widgets::index' => QbTraceScript::forChain($chain),
    ]);

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/widgets'),
        actionRef: new ActionRef('', 'App\\Widgets', 'index'),
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', []),
    );

    (new QueryBuilderParametersExtension)->handle(new OperationDraft, $context);

    return array_values(array_filter(
        $context->components->diagnostics(),
        static fn ($d): bool => $d->code === 'query-builder.no-allowlists-recovered',
    ));
}

it('emits the diagnostic when a paginating terminal recovers no allow-lists', function (): void {
    $diagnostics = noAllowListDiagnostics('QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->paginate()');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->message)->toContain('App\\Widgets::index');
});

it('does not emit the diagnostic when allow-lists were recovered', function (): void {
    $diagnostics = noAllowListDiagnostics(
        'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)->allowedFilters([\'name\'])->paginate()',
    );

    expect($diagnostics)->toBe([]);
});
