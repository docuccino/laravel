<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\SpatieData\WrapResolver;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AuthorData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ComputedWrapData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\DeferredContextProblemData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\HelperContextProblemData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\NestedTransformDisabledData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\OwnResponseProblemData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ProblemDocumentData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\SiblingWrapData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\WrappedData;

/*
 * What the SERVER sends, rendered through the real laravel-data, for every class the root-wrap read has
 * an opinion about. The published envelope is a claim about a wire body, so the claim is checked
 * against the wire body rather than against the read that produced it — a test written from the same
 * premise as the code ratifies the premise instead of checking it.
 *
 * Two rows here deliberately DISAGREE with what the document publishes. They are the degraded answers,
 * pinned as they are with the diagnostic that carries them, because a fixture reshaped until the
 * analyser resolves it proves nothing about the analyser.
 */

it('publishes the envelope the server really sends', function (string $case, callable $render, array $wire, ?string $published): void {
    bootLaravelData('data');

    expect(array_keys($render()))->toBe($wire)
        ->and((new WrapResolver('data'))->key($case))->toBe($published);
})->with([
    // Unwrapped on the wire, and the document says so.
    'a class that strips its own envelope' => [
        ProblemDocumentData::class,
        fn (): array => (new ProblemDocumentData('about:blank', 404))->toProblemResponse(request())->getData(true),
        ['type', 'status'],
        null,
    ],
    'a class that disables wrapping on its own transformation' => [
        OwnResponseProblemData::class,
        fn (): array => (new OwnResponseProblemData('about:blank', 404))->toResponse(request())->getData(true),
        ['type', 'status'],
        null,
    ],
    'the same, with the context built into a local first' => [
        DeferredContextProblemData::class,
        fn (): array => (new DeferredContextProblemData('about:blank', 404))->toResponse(request())->getData(true),
        ['type', 'status'],
        null,
    ],
    // Wrapped on the wire, and the document says so. This is the row a bare `WrapExecutionType`
    // sighting published as unwrapped: the disabling belongs to the author, and the root is wrapped
    // regardless — so the envelope the document omitted was one the server sends.
    'a class that disables wrapping on a nested transformation' => [
        NestedTransformDisabledData::class,
        fn (): array => (new NestedTransformDisabledData(1, new AuthorData('a', 'a@example.com')))->toResponse(request())->getData(true),
        ['data'],
        'data',
    ],
    'a class whose file holds a neighbour that unwraps itself' => [
        SiblingWrapData::class,
        fn (): array => (new SiblingWrapData(1))->toResponse(request())->getData(true),
        ['data'],
        'data',
    ],
    'a class that says nothing about wrapping' => [
        AuthorData::class,
        fn (): array => (new AuthorData('a', 'a@example.com'))->toResponse(request())->getData(true),
        ['data'],
        'data',
    ],
    'a class whose defaultWrap() returns a literal' => [
        WrappedData::class,
        fn (): array => (new WrappedData(1, 'a'))->toResponse(request())->getData(true),
        ['record'],
        'record',
    ],
]);

it('pins the two answers it degrades, and the diagnostic that carries each', function (string $case, callable $render, array $wire, string $published): void {
    bootLaravelData('data');
    $resolver = new WrapResolver('data');

    expect(array_keys($render()))->toBe($wire)
        ->and($resolver->key($case))->toBe($published)
        ->and($resolver->diagnose($case)?->code)->toBe('spatie-data.root-wrap-unsettled')
        ->and($resolver->diagnose($case)?->message)->toContain($case)
        // The envelope this read applied, not the one the finished response carries — the help offers an
        // overlay, which answers that node (docs/design/defect-classes.md §"A diagnostic that asserts an
        // outcome it never reads").
        ->and($resolver->diagnose($case)?->message)->toStartWith('The response shape recovered for ')
        ->and($resolver->diagnose($case)?->message)->not->toContain('is documented with');
})->with([
    // The wire is unwrapped and the document publishes the envelope. Both answers are a lie of the
    // same size to a client, so the envelope falls to what the configuration says the framework does
    // to every root Data response — the half that was read — rather than to an override that was not.
    'a disabling behind a hop the read cannot follow' => [
        HelperContextProblemData::class,
        fn (): array => (new HelperContextProblemData('about:blank', 404))->toResponse(request())->getData(true),
        ['type', 'status'],
        'data',
    ],
    // The wire carries the class's own key and the document publishes the global one, because the
    // override does not return a literal. Same fall, same report.
    'a defaultWrap() that returns no literal' => [
        ComputedWrapData::class,
        fn (): array => (new ComputedWrapData(1))->toResponse(request())->getData(true),
        [ComputedWrapData::ENVELOPE],
        'data',
    ],
]);
