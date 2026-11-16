<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * What a Data object is built from, typed by the analyser where the action builds it — the half of a Data
 * body's link no stub can stand in for. A value counts as a model's column only where it is written
 * `$model->column` on exactly one model class, because a link read off the wrong model's column, or off a
 * value that may be null, sends a client something no route matches. Anything the walk sees done to a Data
 * object that it cannot read stands against every property, so it is recorded too.
 */
it('reads what each construction of a Data class is built from, on the real engine', function (string $method, ?array $constructions): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $trace = FixtureRunner::traceDataSources(
        'app/Http/Controllers/LinkedCourierController.php',
        'App\\Http\\Controllers\\LinkedCourierController',
        $method,
        'App\\Data\\CourierData',
    );

    expect($trace['constructions'])->toBe($constructions);
})->with([
    'from() a model' => ['fromModel', [['kind' => 'model', 'model' => 'App\\Models\\Courier', 'sources' => []]]],
    // Each item of the collection is read as from() reads one model.
    'collect() models' => ['collected', [['kind' => 'model', 'model' => 'App\\Models\\Courier', 'sources' => []]]],
    'the constructor, by name' => ['named', [['kind' => 'arguments', 'model' => null, 'sources' => [
        'id' => ['App\\Models\\Courier', 'id'],
        'code' => ['App\\Models\\Courier', 'code'],
        'name' => ['App\\Models\\Courier', 'name'],
    ]]]],
    // A literal is no column.
    'the constructor, by position' => ['positional', [['kind' => 'arguments', 'model' => null, 'sources' => [
        ['App\\Models\\Courier', 'id'],
        ['App\\Models\\Courier', 'code'],
        null,
    ]]]],
    // Built in the model's own `toDTO()`, read through `$this`; a name passed through `strtoupper()` is not
    // the column.
    'a toDTO() the model writes' => ['handedOut', [['kind' => 'arguments', 'model' => null, 'sources' => [
        'id' => ['App\\Models\\Courier', 'id'],
        'code' => ['App\\Models\\Courier', 'code'],
        'name' => null,
    ]]]],
    'from() an array' => ['payload', [['kind' => 'payload', 'model' => null, 'sources' => [
        'id' => ['App\\Models\\Courier', 'id'],
        'code' => ['App\\Models\\Courier', 'code'],
        'name' => ['App\\Models\\Courier', 'name'],
    ]]]],
    'from() either of two models' => ['either', [['kind' => 'unknown', 'model' => null, 'sources' => []]]],
    'a find() that may be null' => ['maybe', [['kind' => 'arguments', 'model' => null, 'sources' => ['id' => null, 'code' => null, 'name' => null]]]],
    // A request's input under a column's name is whatever the client sent.
    'a value read off the request' => ['requested', [['kind' => 'arguments', 'model' => null, 'sources' => ['id' => null, 'code' => null, 'name' => null]]]],
    // The object the action sends is not the one from() built: its envelope came off afterwards.
    'an object the action then unwraps' => ['unwrapped', [
        ['kind' => 'model', 'model' => 'App\\Models\\Courier', 'sources' => []],
        ['kind' => 'unknown', 'model' => null, 'sources' => []],
    ]],
    // A helper typed on the base class, through a template, adds members over the copied ones.
    'an object a helper changes through the base class' => ['described', [
        ['kind' => 'model', 'model' => 'App\\Models\\Courier', 'sources' => []],
        ['kind' => 'unknown', 'model' => null, 'sources' => []],
    ]],
])->group('fixture');
