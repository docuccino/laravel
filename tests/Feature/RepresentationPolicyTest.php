<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * The operationId representation policy (design §Representation policies) chooses how the default
 * operationId is derived; #[OperationId] still overrides it.
 */
it('derives controller-method operationIds when the policy asks for it', function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $raw['representation']['operation_id'] = 'controller-method';

    $config = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');
    $document = app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document->toArray();

    expect($document['paths']['/api/forms']['get']['operationId'])->toBe('FormController@index')
        ->and($document['paths']['/api/forms/{form}']['get']['operationId'])->toBe('FormController@show');
});

it('leaves operationIds to the route-name strategy by default', function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');

    $config = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');
    $document = app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document->toArray();

    // The workbench routes are unnamed, so the route-name strategy yields no operationId.
    expect($document['paths']['/api/forms']['get'])->not->toHaveKey('operationId');
});
