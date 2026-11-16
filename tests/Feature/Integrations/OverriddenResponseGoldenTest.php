<?php

declare(strict_types=1);

use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\OverriddenResponse\OverriddenResponseController;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Data\FormData;

/*
 * An action that returns one Data class and declares another with `#[Response]`. Inference reads the
 * return type and hoists its body first; the declaration outranks it, and what is left of the inferred
 * type is the trail of the `$ref` it overrode. Nothing else in the golden corpus overrides an inferred
 * response's type, so this is the document that stands in for that population.
 */
beforeEach(function (): void {
    $this->engine = static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: [
        OverriddenResponseController::class.'::show' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(FormData::class), new SourceLocation(''))]),
    ]);
    $this->routes = static function (Router $router): void {
        $router->get('api/zz-overridden', [OverriddenResponseController::class, 'show']);
    };
});

it('publishes the declared response, and the inferred one only as the trail of what it overrode', function (): void {
    $document = localityBuild($this->routes, $this->engine)->document;

    assertGolden('overridden-response.uir.json', (new UirEmitter)->emit($document));
    assertGolden('overridden-response.openapi31.json', (new OpenApi31DownlevelEmitter)->emit($document));
});

it('publishes the overridden type nowhere a consumer reads, and keeps it beside the trail that names it', function (): void {
    $document = localityBuild($this->routes, $this->engine)->document;

    // The OpenAPI document carries no trail, so `FormData` would be a type nothing in it refers to: a dead
    // class in every client generated from it.
    $published = json_decode((new OpenApi31DownlevelEmitter)->emit($document), true, flags: JSON_THROW_ON_ERROR);
    expect($published['components']['schemas'])->not->toHaveKey('FormData')
        ->and(unreachableComponents($published))->toBe([]);

    // A full UIR carries the trail, and the trail's pointer resolves; a `winners` one drops both.
    $full = json_decode((new UirEmitter)->emit($document), true, flags: JSON_THROW_ON_ERROR);
    $winners = json_decode((new UirEmitter)->emit($document, new EmitOptions(provenance: ProvenanceLevel::Winners)), true, flags: JSON_THROW_ON_ERROR);
    expect($full['components']['schemas'])->toHaveKey('FormData')
        ->and(unreachableComponents($full))->toBe([])
        ->and($winners['components']['schemas'])->not->toHaveKey('FormData')
        ->and(unreachableComponents($winners))->toBe([]);
});

it('publishes the same document from a warm cache as from a cold one', function (): void {
    // A warm hit re-registers only what the operation reaches. Unless the trail's bare pointer counts as
    // reaching `FormData`, the cold build has it and the warm one does not.
    $warm = assertWarmEqualsCold($this->routes, $this->routes, $this->engine);

    expect($warm->document->toArray()['components']['schemas'])->toHaveKey('FormData');
});
