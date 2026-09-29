<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\AlphaRules\SlugController;
use Docuccino\Laravel\Tests\Fixtures\AlphaRules\StoreSlugRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;

/*
 * The `alpha` family locked in emitted bytes, in every format. Without `:ascii` Laravel accepts a letter,
 * mark or number from any script, so a pattern of ASCII classes refuses requests the server takes; and a
 * pattern is compiled by consumers that do not all set ECMA-262's `u` flag, so it must read the same with
 * it and without it — which `\p{…}` does not.
 */
function alphaRulesBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-slugs', [SlugController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [SlugController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreSlugRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreSlugRequest::class))->getFileName(),
            StoreSlugRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

/** @return array<string, mixed> The published property schemas of the request body, by field. */
function alphaRulesProperties(): array
{
    $document = json_decode((new UirEmitter)->emit(alphaRulesBuild()->document), flags: JSON_THROW_ON_ERROR);

    return (array) $document->components->schemas->StoreSlugRequest->properties;
}

it('emits the alpha family byte-identical to its committed goldens', function (): void {
    $result = alphaRulesBuild();

    assertGolden('alpha-rules.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('alpha-rules.openapi31.json', (new OpenApi31DownlevelEmitter)->emit($result->document));
    assertGolden('alpha-rules.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
});

it('never refuses a value the server accepts, and agrees with it exactly over ASCII', function (string $field, string $value): void {
    // The server's answer, by Laravel itself, for this one field.
    $accepted = Validator::make([$field => $value], [$field => (new StoreSlugRequest)->rules()[$field]])->passes();
    $schema = alphaRulesProperties()[$field];
    $published = (new SchemaValidator)->validate($value, $schema)->isValid();

    // A value the server takes must pass the document, or a working request reads as invalid. Beyond
    // ASCII the document may be wider — a vague shape is true, a narrow one is not — and inside it the
    // two are the same set, which is what the `:ascii` fields and the plain ones share.
    expect(! $accepted || $published)->toBeTrue(sprintf('the server accepts "%s" and the document refuses it', $value))
        ->and(mb_check_encoding($value, 'ASCII') ? $published === $accepted : true)->toBeTrue(sprintf('the two disagree on "%s"', $value));
})->with(['alpha', 'alpha_ascii', 'alpha_num', 'alpha_num_ascii', 'alpha_dash', 'alpha_dash_ascii', 'handle', 'handle_ascii'])->with([
    'ascii letters' => 'example',
    'ascii letters and digits' => 'Ab9',
    'dash and underscore' => 'a_b-c',
    'a space' => 'a b',
    'a dot' => 'a.b',
    'an at sign' => 'a@b',
    'an accented letter' => 'thème',
    'a combining mark' => "the\u{0301}me",
    'ideographs' => '日本',
    'ideographs and a digit' => '名前1',
    'a non-ascii digit' => 'a٣',
    'accented, dashed, numbered' => 'thème_2-b',
    'an astral letter' => '𝒜𝒷',
    'an emoji' => '😀',
    'an ideographic space' => "日\u{3000}本",
]);

it('agrees with the server on every ASCII character', function (string $field): void {
    $schema = alphaRulesProperties()[$field];
    $rules = [$field => (new StoreSlugRequest)->rules()[$field]];

    $disagreements = [];
    for ($code = 0; $code < 128; $code++) {
        $value = 'a'.chr($code);
        $accepted = Validator::make([$field => $value], $rules)->passes();
        if ((new SchemaValidator)->validate($value, $schema)->isValid() !== $accepted) {
            $disagreements[] = $code;
        }
    }

    expect($disagreements)->toBe([]);
})->with(['alpha', 'alpha_ascii', 'alpha_num', 'alpha_num_ascii', 'alpha_dash', 'alpha_dash_ascii']);

it('publishes patterns that read the same compiled with or without the unicode flag', function (): void {
    $patterns = array_values(array_unique(array_map(
        static fn (object $schema): string => (string) $schema->pattern,
        alphaRulesProperties(),
    )));

    // Three plain forms and three `:ascii` ones, or this proves nothing about either.
    expect($patterns)->toHaveCount(6);

    // PCRE without `u` reads a string as bytes, as an ECMA-262 engine without `u` reads it as code units:
    // a pattern whose verdict survives that is one no consumer can compile into a different set.
    $samples = ['example', 'Ab9', 'a_b-c', 'a b', 'thème', "the\u{0301}me", '日本', '名前1', 'a٣', '𝒜𝒷', '😀', 'pL', '{L}'];
    foreach ($patterns as $pattern) {
        foreach ($samples as $sample) {
            $withFlag = preg_match('/'.$pattern.'/u', $sample);
            $withoutFlag = preg_match('/'.$pattern.'/', $sample);

            expect($withFlag)->not->toBeFalse()
                ->and($withoutFlag)->toBe($withFlag, sprintf('%s on "%s"', $pattern, $sample));
        }
    }
});
