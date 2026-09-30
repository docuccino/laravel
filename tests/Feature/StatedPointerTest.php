<?php

declare(strict_types=1);

use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\ComponentNames\ClaimController;
use Docuccino\Laravel\Tests\Fixtures\ComponentNames\SchemaCatalogueController;
use Docuccino\Laravel\Tests\Support\LocalityEngine;
use Illuminate\Routing\Router;

/**
 * A pointer a value states, through a real build. The catalogue serves a schema document, and its
 * `#[Example]` carries a JSON Reference spelling `UserData` — a component this document publishes too.
 * The pointer is what the server sends, so it leaves the build as written whatever becomes of the
 * component it spells: a contested name is retired and every REFERENCE to it moves, and the example
 * moving with them would name whichever class happened to register first.
 */
it('publishes the example as the server sends it when another route contests the name it spells', function (): void {
    $alone = localityBuild(static function (Router $r): void {
        $r->get('api/zz-user-api', [ClaimController::class, 'apiUser']);
        $r->get('api/zz-user-schema', [SchemaCatalogueController::class, 'show']);
    }, LocalityEngine::factory());

    // An unrelated route whose class shares the short name, and sorts first: it registers `UserData`.
    $contested = localityBuild(static function (Router $r): void {
        $r->get('api/zz-user-admin', [ClaimController::class, 'adminUser']);
        $r->get('api/zz-user-api', [ClaimController::class, 'apiUser']);
        $r->get('api/zz-user-schema', [SchemaCatalogueController::class, 'show']);
    }, LocalityEngine::factory());

    $media = static fn (GenerationResult $result, string $path): mixed => $result->document->toArray()['paths'][$path]['get']['responses']['200']['content']['application/json'] ?? null;
    $sent = ['name' => 'user', 'schema' => ['$ref' => '#/components/schemas/UserData']];

    expect($media($alone, '/api/zz-user-schema')['example'] ?? null)->toBe($sent)
        // The contest is real, and the reference that names the component moves off the retired name…
        ->and(array_keys($contested->document->toArray()['components']['schemas'] ?? []))->not->toContain('UserData')
        ->and($media($contested, '/api/zz-user-api')['schema']['$ref'] ?? null)->toStartWith('#/components/schemas/UserData_')
        // …while the example goes on saying what the server sends.
        ->and($media($contested, '/api/zz-user-schema')['example'] ?? null)->toBe($sent);
});
