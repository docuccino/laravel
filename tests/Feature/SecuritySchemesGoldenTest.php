<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * A byte-locked golden for a fully-secured document: the complete OAuth2 + API-key scheme
 * catalogue in components.securitySchemes plus a document-level security requirement. Guards the
 * security-scheme emission path against silent drift.
 */
it('emits a secured document byte-identical to its committed golden', function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    $raw['info'] = ['title' => 'Secured API', 'version' => '1.0.0'];
    $raw['security'] = [
        'schemes' => [
            'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            'oauth2' => ['type' => 'oauth2', 'flows' => [
                'authorizationCode' => [
                    'authorizationUrl' => 'https://example.test/oauth/authorize',
                    'tokenUrl' => 'https://example.test/oauth/token',
                    'scopes' => ['forms:read' => 'Read forms', 'forms:write' => 'Manage forms'],
                ],
            ]],
        ],
        'document' => [['oauth2' => ['forms:read']], ['apiKey' => []]],
    ];

    $config = app(DocumentConfigFactory::class)->make('secured', $raw, 'skeleton');
    $emitted = (new UirEmitter)->emit(app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document);

    assertGolden('workbench-secured.uir.json', $emitted);
});
