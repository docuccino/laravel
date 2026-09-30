<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Extensions\EnumerableTypeToSchema;
use Docuccino\Laravel\Tests\Fixtures\Collections\Book;
use Docuccino\Laravel\Tests\Fixtures\Collections\CollectedBook;
use Docuccino\Laravel\Tests\Fixtures\Collections\InheritedWrappedBook;
use Docuccino\Laravel\Tests\Fixtures\Collections\KeyedBook;
use Docuccino\Laravel\Tests\Fixtures\Collections\PropertyCollectedBook;
use Docuccino\Laravel\Tests\Fixtures\Collections\TraitCollectedBook;
use Docuccino\Laravel\Tests\Fixtures\Collections\WrappedBook;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\LazyCollection;

/**
 * An Eloquent collection is built as the class its model configures — `$collectionClass`, `#[CollectedBy]` or
 * its own `newCollection()` — while Larastan types every one of them as the framework's collection, and every
 * `new static` a call like `values()` makes is the configured class again. So each row here is typed as the
 * analyser types it, run for real, and held to what the framework sends: the items are claimed only where
 * the configured class is sent as the array it holds.
 */
beforeEach(function (): void {
    Schema::create('books', static function (Blueprint $table): void {
        $table->id();
        $table->string('title');
    });
    Book::query()->insert([['id' => 5, 'title' => 'c'], ['id' => 9, 'title' => 'a'], ['id' => 12, 'title' => 'b']]);

    $this->published = static fn (ClassT $type): array => (new SchemaConverter([new EnumerableTypeToSchema], new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy))->toSchema($type)->schema;
    $this->sent = static fn (mixed $value): mixed => json_decode((string) Router::toResponse(Request::create('/'), $value)->getContent(), true);
});

it('claims the items only where the collection its model configures is sent as the array it holds', function (string $model, Closure $collection, bool $items): void {
    // `Model::all()`, `->get()`, a relation's rows and every `new static` over them: the framework's
    // collection of the model, as Larastan names it.
    $type = new ClassT(EloquentCollection::class, [ScalarT::int(), new ClassT($model)]);
    $sent = ($this->sent)($collection());
    $isRows = is_array($sent) && $sent !== [] && array_filter($sent, static fn (mixed $row): bool => ! is_array($row) || ! array_key_exists('title', $row)) === [];

    // Items claimed exactly where the body holds the rows; nothing claimed where it holds something else.
    expect(($this->published)($type) !== [])->toBe($items)
        ->and($isRows)->toBe($items || $model === KeyedBook::class || $model === TraitCollectedBook::class);
})->with([
    'a model configuring nothing' => [Book::class, static fn (): Collection => Book::all()->values(), true],
    // A collection keying its rows sends them as an object, of the rows still: an array or object of them.
    '$collectionClass, through values()' => [PropertyCollectedBook::class, static fn (): Collection => PropertyCollectedBook::query()->get()->values(), true],
    '$collectionClass, through sortBy()->values()' => [PropertyCollectedBook::class, static fn (): Collection => PropertyCollectedBook::all()->sortBy('title')->values(), true],
    '#[CollectedBy], through values()' => [CollectedBook::class, static fn (): Collection => CollectedBook::all()->values(), true],
    // A collection sending its own shape.
    '$collectionClass sending its own shape' => [WrappedBook::class, static fn (): Collection => WrappedBook::all()->values(), false],
    '$collectionClass inherited from a parent' => [InheritedWrappedBook::class, static fn (): Collection => InheritedWrappedBook::all()->values(), false],
    // A `newCollection()` of the model's own, or a trait's: whatever it builds is out of sight.
    'its own newCollection()' => [KeyedBook::class, static fn (): Collection => KeyedBook::all()->values(), false],
    'a trait\'s newCollection()' => [TraitCollectedBook::class, static fn (): Collection => TraitCollectedBook::query()->get()->values(), false],
]);

it('claims nothing of an Eloquent collection whose model it cannot read', function (?ClassT $item): void {
    expect(($this->published)(new ClassT(EloquentCollection::class, $item === null ? [] : [ScalarT::int(), $item])))->toBe([]);
})->with([
    'no generics' => [null],
    'the abstract model' => [new ClassT(Model::class)],
    'a class that is not a model' => [new ClassT(stdClass::class)],
]);

it('reads the class it is handed for a collection no model configures', function (): void {
    // The base and lazy collections have no configured class: the named one is the one built.
    expect(($this->published)(new ClassT(Collection::class, [ScalarT::int(), ScalarT::string()])))->not->toBe([])
        ->and(($this->published)(new ClassT(LazyCollection::class, [ScalarT::int(), ScalarT::string()])))->not->toBe([]);
});
