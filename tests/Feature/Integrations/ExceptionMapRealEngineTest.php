<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * What an application gets when `$exceptions->map()` translates a missing model before it renders. Both
 * halves are real: the mapper's returns come from the real engine reading the fixture app's own mapper,
 * and the document around them is the full pipeline — so the binding's 404 and the action's alike are
 * published as whatever the translation renders to.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('publishes a missing model as the exception the real engine reads its mapper returning', function (string $method, string $status, bool $deferred): void {
    $source = (string) file_get_contents(FixtureRunner::path('app/Exceptions/ExceptionMappers.php'));
    $line = 0;
    foreach (explode("\n", $source) as $index => $text) {
        if (str_contains($text, 'public function '.$method.'(')) {
            $line = $index + 3;
        }
    }
    expect($line)->toBeGreaterThan(3);

    $symbol = registerExceptionMap(
        ModelNotFoundException::class,
        static fn (ModelNotFoundException $e) => new ConflictHttpException('', $e),
        ModelNotFoundException::class,
    );
    app()->instance(TypeEngine::class, WorkbenchEngine::make([$symbol => ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/ExceptionMappers.php',
        '',
        '',
        line: $line,
        param: 'e',
        narrowType: ModelNotFoundException::class,
        returnsExceptions: true,
    ))]));

    $result = generateDocument();
    $responses = $result->document->toArray()['paths']['/api/forms/{form}']['get']['responses'];
    $warned = array_filter(
        $result->diagnostics,
        static fn (Diagnostic $d): bool => $d->code === 'inferred-handler.too-dynamic' && str_contains($d->message, 'exception map'),
    ) !== [];

    expect(array_map('strval', array_keys($responses)))->toContain($status)->not->toContain('404')
        ->and($warned)->toBe($deferred);
})->with([
    // `ConflictHttpException` pins its 409 in a vendor constructor; the framework table is what places it.
    'a framework class' => ['frameworkClass', '409', false],
    // The class is read and the status is not: filed under the placeholder an unread status takes, and said.
    'a status read at run time' => ['dynamicMissing', '500', true],
])->group('fixture');
