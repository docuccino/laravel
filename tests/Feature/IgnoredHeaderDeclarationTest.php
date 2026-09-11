<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\IgnoredHeaderController;

/**
 * The real path for `document.ignored-header-declaration`: an author writes `#[HeaderParameter]`
 * naming a header OpenAPI reserves, and the build has to say so. Asserted end to end rather than
 * against a hand-built array, because the attribute, the assembly and the audit are three different
 * layers and the diagnostic is only worth anything if it survives all of them. Routes registered
 * ad-hoc so no committed golden churns.
 */
function ignoredHeaderDocument(): GenerationResult
{
    /** @var Router $router */
    $router = app('router');
    $router->get('api/headers/negotiated', [IgnoredHeaderController::class, 'negotiated']);
    $router->get('api/headers/credentialed', [IgnoredHeaderController::class, 'credentialed']);
    $router->get('api/headers/forms/{form}', [IgnoredHeaderController::class, 'tenanted']);

    return generateDocument();
}

it('tells the author which declaration says nothing, and how loudly it matters', function (): void {
    $reported = array_values(array_map(
        static fn (Diagnostic $d): string => $d->severity->value.': '.$d->message,
        array_filter(
            ignoredHeaderDocument()->diagnostics,
            static fn (Diagnostic $d): bool => $d->code === 'document.ignored-header-declaration',
        ),
    ));

    // Exactly two: the ordinary `X-Tenant` beside them is what makes the pair a rule rather than the
    // audit reporting every header declaration it sees.
    // Operations in signature order, which is why `credentialed` comes first.
    expect($reported)->toHaveCount(2)
        // Nothing else says this operation needs a credential, so the document now says nothing at all.
        ->and($reported[0])->toStartWith('warning: GET /api/headers/credentialed declares a header parameter named "Authorization"')
        // The media types this operation returns are in its responses, so the declaration was
        // redundant and only its wording is gone.
        ->and($reported[1])->toStartWith('info: GET /api/headers/negotiated declares a header parameter named "Accept"');
});

it('still publishes what it reports, so nothing the author wrote leaves the document unasked', function (): void {
    $document = ignoredHeaderDocument()->document->toArray();

    $declared = static function (string $path) use ($document): array {
        /** @var list<array<string, mixed>> $parameters */
        $parameters = $document['paths'][$path]['get']['parameters'] ?? [];

        return array_map(
            static fn (array $p): string => $p['name'].': '.($p['description'] ?? ''),
            array_values(array_filter($parameters, static fn (array $p): bool => $p['in'] === 'header')),
        );
    };

    expect($declared('/api/headers/negotiated'))->toBe(['Accept: Ask for JSON.'])
        ->and($declared('/api/headers/credentialed'))->toBe(['Authorization: Bearer your API token.'])
        ->and($declared('/api/headers/forms/{form}'))->toBe(['X-Tenant: Which tenant the form belongs to.']);
});

/*
 * The other half of the population, and the one the adapter can only reach through an overlay: a
 * header parameter written ONCE under `components.parameters` and pointed at from more than one
 * operation. Docuccino mints no such declaration of its own — the version header is the only shared
 * parameter it publishes, and it refuses a name OpenAPI reserves rather than minting one
 * ({@see ApiVersionHeaderComponentTest}) — so an overlay is what stands in for the author who wrote
 * one, which is exactly who this reports to.
 *
 * End to end rather than against a hand-built array for the same reason as above, plus one this test
 * alone can prove: the audit reads the FINISHED document, so it has to still be looking at a `$ref`
 * by then. Inlined anywhere on the way past and the count below would be 2.
 */
it('reports a declaration the operations share once, against the component that holds it', function (): void {
    $dir = sys_get_temp_dir().'/docuccino-shared-header-'.uniqid();
    mkdir($dir);

    file_put_contents($dir.'/shared.yaml', <<<'YAML'
        overlay: 1.0.0
        actions:
          - target: $.components
            update:
              parameters:
                SharedCredential:
                  name: Authorization
                  in: header
                  description: Bearer your API token.
                  schema:
                    type: string
          - target: $.paths['/api/headers/credentialed'].get.parameters
            update:
              - $ref: '#/components/parameters/SharedCredential'
          - target: $.paths['/api/headers/forms/{form}'].get.parameters
            update:
              - $ref: '#/components/parameters/SharedCredential'
        YAML);

    try {
        /** @var Router $router */
        $router = app('router');
        $router->get('api/headers/negotiated', [IgnoredHeaderController::class, 'negotiated']);
        $router->get('api/headers/credentialed', [IgnoredHeaderController::class, 'credentialed']);
        $router->get('api/headers/forms/{form}', [IgnoredHeaderController::class, 'tenanted']);

        setBuild('documents.default.overlays', [$dir.'/*.yaml']);

        // Built the way the commands build it: `DocumentBuilder` is what applies overlays, and an
        // overlay is the whole point of this one.
        $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

        $reported = array_values(array_map(
            static fn (Diagnostic $d): string => $d->severity->value.': '.$d->message,
            array_filter(
                $result->diagnostics,
                static fn (Diagnostic $d): bool => $d->code === 'document.ignored-header-declaration',
            ),
        ));

        $document = $result->document->toArray();
    } finally {
        array_map(unlink(...), glob($dir.'/*') ?: []);
        @rmdir($dir);
    }

    // Two operations point at the component and it earns ONE diagnostic, naming the pointer the
    // author wrote rather than either operation that reaches it.
    expect($reported)->toHaveCount(2)
        ->and($reported[0])->toStartWith('warning: The component #/components/parameters/SharedCredential declares a header parameter named "Authorization"')
        // The positive control, inline on an operation the overlay left alone: the audit is still
        // seeing declarations, so the single report above is the grouping rather than silence.
        ->and($reported[1])->toStartWith('info: GET /api/headers/negotiated declares a header parameter named "Accept"')
        // And the two really are pointing at it, so the count of 1 is over a population of 2.
        ->and($document['paths']['/api/headers/credentialed']['get']['parameters'][0]['$ref'] ?? null)
        ->toBe('#/components/parameters/SharedCredential')
        ->and($document['paths']['/api/headers/forms/{form}']['get']['parameters'][0]['$ref'] ?? null)
        ->toBe('#/components/parameters/SharedCredential');
});
