<?php

declare(strict_types=1);

use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Attributes\Response;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Extensions\InferredResponsesExtension;
use Docuccino\Laravel\Support\FrameworkClasses;

/**
 * `inferred-response.payload-unrecoverable` reports a body the analyzer got nothing from. Unlike the
 * redirect range, the finished document cannot answer this one — a response with no recovered body is
 * published with no body, which reads exactly like a deliberately empty one — so the notice checks the
 * one layer that can settle it: whether the author named the body themselves.
 */
function unrecoverableCodes(DType|array $returnTypes, array $attributes): array
{
    $action = 'Workbench\\App\\Http\\Controllers\\FormController::index';

    $returns = array_map(
        static fn (DType $type): ReturnSite => new ReturnSite($type, new SourceLocation('')),
        is_array($returnTypes) ? $returnTypes : [$returnTypes],
    );

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/things'),
        actionRef: new ActionRef('', 'Workbench\\App\\Http\\Controllers\\FormController', 'index'),
        attributes: new AttributeSet($attributes),
        engine: new StubTypeEngine(analyses: [
            $action => new ActionAnalysis(returns: $returns),
        ]),
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
    );

    (new InferredResponsesExtension)->handle(new OperationDraft, $context);

    return array_map(static fn ($d): string => $d->code, $context->components->diagnostics());
}

it('reports a bare framework response whose body nothing names', function (string $fqcn): void {
    expect(unrecoverableCodes(new ClassT($fqcn), []))->toContain('inferred-response.payload-unrecoverable');
})->with([
    'a bare JsonResponse' => [FrameworkClasses::JSON_RESPONSE],
    'a response class that proves nothing' => [FrameworkClasses::RESPONSE_BASE],
]);

it('stays silent when the author named the body themselves', function (object $attribute): void {
    // Being told to declare what you already declared is how a channel stops being read: the attribute
    // layer settles exactly the fact this reports missing.
    expect(unrecoverableCodes(new ClassT(FrameworkClasses::JSON_RESPONSE), [$attribute]))
        ->not->toContain('inferred-response.payload-unrecoverable');
})->with([
    'a declared body type' => [new Response(status: 200, type: 'Workbench\\App\\Data\\FormData')],
    'a declared body and media type' => [new Response(status: 200, type: 'string', mediaType: 'text/csv')],
    'the response dropped outright' => [new IgnoreResponse(status: 200)],
]);

it('still reports where the declaration settles some other response', function (object $attribute): void {
    // A status the unrecovered body never landed on says nothing about it, and #[Response] with no
    // `type:` names a status and leaves the body as unrecovered as it found it.
    expect(unrecoverableCodes(new ClassT(FrameworkClasses::JSON_RESPONSE), [$attribute]))
        ->toContain('inferred-response.payload-unrecoverable');
})->with([
    'another status entirely' => [new Response(status: 404, type: 'string')],
    'a status with no body named' => [new Response(status: 200)],
    'another status dropped' => [new IgnoreResponse(status: 404)],
]);

it('speaks once per class however many return paths reach it', function (): void {
    // The class is what the reader acts on, so the statuses it landed on are collected as a set: two
    // bare returns of one class are one notice, and the declaration that silences it has to cover every
    // status in that set rather than whichever return site was seen first.
    $codes = unrecoverableCodes([
        new ClassT(FrameworkClasses::JSON_RESPONSE),
        new ClassT(FrameworkClasses::JSON_RESPONSE),
        new ClassT(FrameworkClasses::RESPONSE_BASE),
    ], []);

    expect(array_count_values($codes)['inferred-response.payload-unrecoverable'] ?? 0)->toBe(2);
});

it('is silenced only once every status the class reached is named', function (array $attributes, bool $silent): void {
    // Both return paths land on 200 today, so one declaration covers the set; the rule is that the SET
    // has to be covered, not that one member of it happens to be.
    $codes = unrecoverableCodes(
        [new ClassT(FrameworkClasses::JSON_RESPONSE), new ClassT(FrameworkClasses::JSON_RESPONSE)],
        $attributes,
    );

    expect($codes)
        ->when($silent, fn ($c) => $c->not->toContain('inferred-response.payload-unrecoverable'))
        ->when(! $silent, fn ($c) => $c->toContain('inferred-response.payload-unrecoverable'));
})->with([
    'the status they reached' => [[new Response(status: 200, type: 'string')], true],
    'a status they did not' => [[new Response(status: 202, type: 'string')], false],
]);

it('needs a streamed body given a media type as well as a shape', function (?string $mediaType, bool $silent): void {
    // What a stream loses is its media type, so that is what a declaration has to name. `mediaType:`
    // left unwritten is the JSON default, and a parameter default is nobody saying anything.
    $codes = unrecoverableCodes(
        new ClassT(FrameworkClasses::STREAMED_RESPONSE),
        [new Response(status: 200, type: 'string', mediaType: $mediaType)],
    );

    expect($codes)
        ->when($silent, fn ($c) => $c->not->toContain('inferred-response.payload-unrecoverable'))
        ->when(! $silent, fn ($c) => $c->toContain('inferred-response.payload-unrecoverable'));
})->with([
    'left to the default' => [null, false],
    'named at the attribute' => ['text/csv', true],
]);
