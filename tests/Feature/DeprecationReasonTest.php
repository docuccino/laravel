<?php

declare(strict_types=1);

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\Attributes\DeprecatedController;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * A deprecation reason reaches the consumer or it reaches nobody: `deprecated: true` says the operation
 * is going away, and the description is the only member OpenAPI gives the why. Both spellings publish
 * the same paragraph, the attribute's reason outranks the docblock tag's, and either survives an
 * attribute description replacing the docblock prose.
 */
beforeEach(function (): void {
    $this->operations = static function (): array {
        $router = app('router');
        foreach (['attributeReason', 'reasonBesideDescription', 'bareAttributeWithDocblockReason', 'bothReasons', 'noReason'] as $action) {
            $router->get('api/zz-deprecated/'.$action, [DeprecatedController::class, $action]);
        }

        app()->instance(TypeEngine::class, WorkbenchEngine::make());

        $paths = generateDocument()->document->toArray()['paths'];

        $operations = [];
        foreach ($paths as $path => $item) {
            if (str_starts_with((string) $path, '/api/zz-deprecated/')) {
                $operations[substr((string) $path, strlen('/api/zz-deprecated/'))] = $item['get'];
            }
        }

        return $operations;
    };
});

it('publishes the reason as a description paragraph, whichever spelling states it', function (string $action, ?string $description): void {
    $operation = ($this->operations)()[$action];

    expect($operation['deprecated'])->toBeTrue()
        ->and($operation['description'] ?? null)->toBe($description);
})->with([
    // Joined to the docblock's own description rather than replacing it.
    'the attribute reason' => [
        'attributeReason',
        "The long version, from the docblock.\n\n**Deprecated:** Use /api/v2/widgets instead.",
    ],
    // #[Description] replaces the docblock prose; the reason is written with it, not after it, because a
    // second patch at one layer would be shadowed.
    'the reason beside an attribute description' => [
        'reasonBesideDescription',
        "The consumer-facing version.\n\n**Deprecated:** Use /api/v2/widgets instead.",
    ],
    // A reasonless attribute leaves the docblock tag's text as the only thing said, so it is published.
    'the docblock tag under a bare attribute' => [
        'bareAttributeWithDocblockReason',
        '**Deprecated:** Use /api/v2/widgets instead.',
    ],
    // Both stated: the attribute wins, exactly as it does for the flag — and only one paragraph lands.
    'both, the attribute winning' => [
        'bothReasons',
        "**Deprecated:** The attribute's reason.",
    ],
    // Nothing to say, nothing said: a bare attribute never invents prose.
    'neither' => ['noReason', null],
]);
