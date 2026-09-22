<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\ExportTarget;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Config\DocumentEmitOptions;

/**
 * `docuccino:export --format=arazzo` as the COMMAND runs it, which nothing covered: the emitter's own
 * tests prove it returns the right bytes, and these prove the command does the right thing with them.
 *
 * Both halves were review findings rather than guesses. A document with no workflows emits no bytes and
 * the command wrote them anyway — truncating a committed artifact to zero bytes and printing "Wrote".
 * And the description names the OpenAPI file its steps live in, which only the adapter knows.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 2));
    bindStubEngine();

    setDocuments(['default' => [
        'info' => ['title' => 'Ping API', 'version' => '1.0.0'],
        // A route set no #[WorkflowStep] reaches, so the document carries no workflows at all.
        'routes' => ['include' => ['api/ping']],
        'error_responses' => 'none',
    ]]);
});

function arazzoOut(string $name): string
{
    return sys_get_temp_dir().'/docuccino-arazzo-'.$name.'-'.getmypid().'.arazzo.json';
}

it('writes no file at all for a document that declares no workflows', function (): void {
    $out = arazzoOut('empty');
    @unlink($out);

    $this->artisan('docuccino:export', ['--format' => 'arazzo', '--out' => $out])->assertSuccessful();

    // The whole point: Arazzo has no empty form, so there is nothing to write — and writing the zero
    // bytes anyway is what this used to do.
    expect(is_file($out))->toBeFalse();
});

it('leaves a committed artifact alone rather than truncating it', function (): void {
    // The consequence a reader actually meets, executed rather than argued: yesterday's description is
    // still readable after a build that had nothing to say.
    $out = arazzoOut('committed');
    file_put_contents($out, "arazzo: 1.1.0\n# yesterday's description\n");

    $this->artisan('docuccino:export', ['--format' => 'arazzo', '--out' => $out])->assertSuccessful();

    expect(file_get_contents($out))->toContain("yesterday's description");

    @unlink($out);
});

it('says it wrote nothing rather than claiming it wrote the file', function (): void {
    $this->artisan('docuccino:export', ['--format' => 'arazzo', '--out' => arazzoOut('said')])
        ->expectsOutputToContain('Wrote nothing')
        ->assertSuccessful();
});

/*
 * The source description the Arazzo file points at. Asserted on the options the document decides rather
 * than through a full export, because that is the seam the three readers share — export, validate and
 * the contract assertions all go through `DocumentEmitOptions`, and a value set anywhere else would
 * make `docuccino:validate` re-emit a different document than the one shipped.
 */
it('names the OpenAPI target the document configures, relative to the file that points at it', function (string $case, array $targets, string $expected): void {
    setDocuments(['default' => [
        'info' => ['title' => 'Ping API', 'version' => '1.0.0'],
        'routes' => ['include' => ['api/ping']],
        'error_responses' => 'none',
        'export' => ['targets' => $targets],
    ]]);

    $config = app(DocumentConfigFactory::class)->make('default', documentSettings(), 'skeleton');
    $arazzo = new ExportTarget('arazzo', (string) array_column($targets, 'path', 'format')['arazzo']);

    expect(DocumentEmitOptions::canonical($config, $arazzo)->sourceUrl)->toBe($expected)
        ->and($case)->not->toBe('');
})->with([
    'side by side' => [
        'side by side',
        [
            ['format' => 'openapi-3.2', 'path' => 'docs/api/openapi.yaml'],
            ['format' => 'arazzo', 'path' => 'docs/api/workflows.arazzo.json'],
        ],
        'openapi.yaml',
    ],
    'the openapi file a directory up' => [
        'the openapi file a directory up',
        [
            ['format' => 'openapi-3.2', 'path' => 'docs/openapi.json'],
            ['format' => 'arazzo', 'path' => 'docs/workflows/checkout.arazzo.json'],
        ],
        '../openapi.json',
    ],
    'the openapi file a directory down' => [
        'the openapi file a directory down',
        [
            ['format' => 'openapi-3.1', 'path' => 'docs/spec/openapi.json'],
            ['format' => 'arazzo', 'path' => 'docs/workflows.arazzo.json'],
        ],
        'spec/openapi.json',
    ],
    'no openapi target at all' => [
        'no openapi target at all',
        [['format' => 'arazzo', 'path' => 'docs/workflows.arazzo.json']],
        // Arazzo requires a source description, so the conventional name beats none.
        'openapi.json',
    ],
]);

it('never names the machine that built the document', function (): void {
    // The leak this would be: an absolute path in a PUBLISHED artifact naming the developer's home
    // directory. Asserted rather than assumed, because the obvious implementation reaches for
    // `Paths::absolute()` and that is exactly what it must not do.
    setDocuments(['default' => [
        'info' => ['title' => 'Ping API', 'version' => '1.0.0'],
        'routes' => ['include' => ['api/ping']],
        'error_responses' => 'none',
        'export' => ['targets' => [
            ['format' => 'openapi-3.2', 'path' => 'docs/openapi.json'],
            ['format' => 'arazzo', 'path' => 'docs/workflows.arazzo.json'],
        ]],
    ]]);

    $config = app(DocumentConfigFactory::class)->make('default', documentSettings(), 'skeleton');
    $url = DocumentEmitOptions::canonical($config, new ExportTarget('arazzo', 'docs/workflows.arazzo.json'))->sourceUrl;

    expect($url)->not->toContain(base_path())
        ->and($url)->not->toStartWith('/')
        ->and($url)->toBe('openapi.json');
});
