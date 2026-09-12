<?php

declare(strict_types=1);

use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Extensions\AttributeParametersExtension;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Tests\Support\TraceScript;

/**
 * What `query-builder.unresolved-entry` may claim. It is a WARNING, so it can fail a build under
 * `--fail-on warning`, and the entry it reports has no name — the expression that would have given it one
 * is the one that did not fold — so there is nothing to look an outcome up by. It therefore states the
 * loss to the RECOVERY, which is true whatever an author documents by hand, and not the contents of a
 * document it cannot check.
 *
 * @return array{0: array<string, array<string, mixed>>, 1: list<Diagnostic>}
 */
function runUnresolvedEntry(array $attributes = []): array
{
    // `$dynamic` is a variable, so the entry cannot be folded and nothing names the filter it registers.
    $chain = 'QueryBuilder::for(\\Workbench\\App\\Models\\Gadget::class)'
        ."->allowedFilters([\$dynamic, 'name'])->paginate()";

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: new ActionRef('', 'App\\Gadgets', 'index'),
        attributes: new AttributeSet($attributes),
        engine: new StubTypeEngine(traces: ['App\\Gadgets::index' => TraceScript::forChain($chain)]),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
        document: new DocumentConfig('default', []),
    );

    $operation = new OperationDraft;
    (new QueryBuilderParametersExtension)->handle($operation, $context);
    (new AttributeParametersExtension)->handle($operation, $context);

    $byName = [];
    foreach ($operation->freeze()->parameters as $parameter) {
        $byName[$parameter->name] = $parameter->toArray();
    }

    return [$byName, diagnosticsCoded($context->components->diagnostics(), 'query-builder.unresolved-entry')];
}

it('reports the entry it could not read against the chain, naming the call site', function (): void {
    [$byName, $reports] = runUnresolvedEntry();

    // Anti-vacuity: the readable entry beside it is still documented, so this is one entry lost and not
    // a chain that failed to recover at all.
    expect($byName)->toHaveKey('filter[name]')
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]->severity)->toBe(Severity::Warning)
        ->and($reports[0]->message)->toContain('allowedFilters entry at')
        ->and($reports[0]->message)->toContain('recovered from the chain');
});

it('does not tell the author the document lacks a parameter the same build publishes', function (): void {
    // The entry is unreadable AND the action documents that filter itself — the ordinary answer to an
    // expression that will never fold. A clause saying it is "omitted from the docs" would then be false
    // of the document this very build emits, on a warning that can fail CI and that no edit clears.
    [$byName, $reports] = runUnresolvedEntry([new QueryParameter('filter[stage]', type: 'string')]);

    expect($byName['filter[stage]']['schema']['type'])->toBe('string')
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]->message)->not->toContain('omitted from the docs')
        // What stays true either way, and what the reader can still act on: the chain is short an entry.
        ->and($reports[0]->message)->toContain('is in the allow-list recovered from the chain')
        ->and($reports[0]->help)->toContain('so it can be recovered');
});
