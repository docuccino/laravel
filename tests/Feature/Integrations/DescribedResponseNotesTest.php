<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\Logbook;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\LogbookController;
use Docuccino\Laravel\Tests\Fixtures\DescribedNotes\LogbookData;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;

/*
 * Response properties whose wire format no keyword can carry — an Eloquent date cast naming its own
 * pattern, a Data class under an app `data.date_format` no `format` names, a Data property cast as a Unix
 * timestamp — with and without a description their author wrote: a model's `@property` summary, a Data
 * property's docblock or `#[Description]`. Beside them, the columns whose format a keyword does carry, and a
 * model `$dateFormat` that only decides how the columns are stored. No golden held a response property that
 * had both.
 */
function describedResponseNotesBuild(): GenerationResult
{
    bootLaravelData(null);
    config()->set('data.date_format', 'd/m/Y');

    $carbon = new ClassT(Carbon::class);
    $immutable = new ClassT(CarbonImmutable::class);

    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            // The `@property` tags as the engine reads them, summaries included.
            Logbook::class => new ClassMetadata(Logbook::class, [
                new PropertyMetadata('id', ScalarT::int()),
                new PropertyMetadata('closed_on', $carbon, 'The day the logbook was closed.'),
                new PropertyMetadata('opened_on', $carbon),
                new PropertyMetadata('sealed_on', UnionT::of([$carbon, new NullT]), 'When the logbook was sealed, if it has been.'),
                new PropertyMetadata('created_at', $carbon, 'When the logbook was started.'),
                new PropertyMetadata('updated_at', $carbon),
            ]),
            LogbookData::class => new ClassMetadata(LogbookData::class, [
                new PropertyMetadata('closedOn', $immutable, 'The day the logbook was closed.'),
                new PropertyMetadata('openedOn', $immutable),
                new PropertyMetadata('sealedOn', $immutable),
                new PropertyMetadata('reviewedOn', UnionT::of([$immutable, new NullT]), 'When the logbook was last reviewed, if it has been.'),
                new PropertyMetadata('stampedAt', $immutable, 'When the logbook was stamped.'),
                new PropertyMetadata('archivedOn', $immutable, 'When the logbook was archived.'),
            ]),
        ],
        analysisOverrides: [
            LogbookController::class.'::show' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(Logbook::class), new SourceLocation(''))]),
            LogbookController::class.'::summary' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(LogbookData::class), new SourceLocation(''))]),
        ],
    );

    return localityBuild(static function (Router $router): void {
        $router->get('api/zz-logbooks', [LogbookController::class, 'show']);
        $router->get('api/zz-logbooks/summary', [LogbookController::class, 'summary']);
    }, $engine);
}

it('emits response properties their author described byte-identical to their committed golden', function (): void {
    assertGolden('described-response-notes.uir.json', (new UirEmitter)->emit(describedResponseNotesBuild()->document));
});

/**
 * One property of a response body, followed through its component.
 *
 * @return array<string, mixed>
 */
function describedResponseProperty(string $path, string $property): array
{
    $document = emittedArray(describedResponseNotesBuild());
    $schema = resolveSchema($document, $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema']);

    return $schema['properties'][$property];
}

/*
 * The pattern a date is written with is a fact of what the server sends, true whatever prose the property
 * carries, and no keyword states it — so the sentence naming it is all a consumer has to parse the value
 * by. An author's description says what the property MEANS; it leads, and the pattern stands beneath it
 * as a paragraph of its own, as a request field's rules do beneath theirs.
 */
it('keeps the pattern a date is written with beneath the description an author writes', function (string $path, string $property, string $expected): void {
    expect(describedResponseProperty($path, $property)['description'] ?? null)->toBe($expected);
})->with([
    'a date cast, beneath a @property summary' => ['/api/zz-logbooks', 'closed_on', "The day the logbook was closed.\n\nSerialized using the date format \"d/m/Y\"."],
    'a nullable date-time cast, beneath a @property summary' => ['/api/zz-logbooks', 'sealed_on', "When the logbook was sealed, if it has been.\n\nSerialized using the date format \"d/m/Y H:i\"."],
    'a Data property, beneath its docblock' => ['/api/zz-logbooks/summary', 'closedOn', "The day the logbook was closed.\n\nSerialized using the date format \"d/m/Y\"."],
    'a Data property, beneath its #[Description]' => ['/api/zz-logbooks/summary', 'openedOn', "When the logbook was opened.\n\nSerialized using the date format \"d/m/Y\"."],
    'a nullable Data property, beneath its docblock' => ['/api/zz-logbooks/summary', 'reviewedOn', "When the logbook was last reviewed, if it has been.\n\nSerialized using the date format \"d/m/Y\"."],
]);

it('publishes the pattern alone where nobody described the date', function (string $path, string $property): void {
    expect(describedResponseProperty($path, $property))->toBe(['description' => 'Serialized using the date format "d/m/Y".', 'type' => 'string']);
})->with([
    'a date cast' => ['/api/zz-logbooks', 'opened_on'],
    'a Data property' => ['/api/zz-logbooks/summary', 'sealedOn'],
]);

it('adds nothing beneath the description of a date whose format a keyword states', function (): void {
    // The keyword already says it, and `$dateFormat` only decides how the column is stored.
    expect(describedResponseProperty('/api/zz-logbooks', 'created_at'))->toBe([
        'description' => 'When the logbook was started.',
        'type' => 'string',
        'format' => 'date-time',
    ]);
});
