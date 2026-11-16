<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Integrations\SpatieData\WrapResolver;
use Docuccino\Laravel\Links\BodyColumns;
use Docuccino\Laravel\Links\DataColumns;
use Docuccino\Laravel\Links\DataConstruction;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\BareVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\CamelVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\CollectingVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\ConstructingVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\DefaultedVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\FleetVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\HandedMapperVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\LazyVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\LiteralVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\MadeVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\MutableVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\NestedInputVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\NormalisingVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\OverridingVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\OwnMapperVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\PaintedVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\SerialisingVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\UnpromotedVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\UnsettledVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\VanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\LiteralVan;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Parcel;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\TranslatedVan;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Van;

/**
 * Which model column a Data body's member carries, read as laravel-data copies it. A link sends a client
 * the value at that member to a route that looks the column up, so a member counts only where the value
 * laravel-data writes there is the column's value as stored — the rows state what makes each one true,
 * and the refusals what laravel-data would do instead.
 */
function dataLinkContext(): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/vans'),
        actionRef: new ActionRef('', 'App\\VanController', 'store'),
        attributes: new AttributeSet([]),
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
    );
}

/**
 * @param  list<DataConstruction>  $built
 * @param  list<string>|null  $published
 * @return array<string, array{0: string, 1: string}>
 */
function dataCarried(string $data, array $built, ?array $published = null, ?DataColumns $columns = null, ?DataClassReflector $reflector = null): array
{
    $reflector ??= new DataClassReflector;
    $published ??= array_map(
        static fn (ReflectionProperty $property): string => $reflector->outputName($data, $property->getName()),
        (new ReflectionClass($data))->getProperties(ReflectionProperty::IS_PUBLIC),
    );

    return ($columns ?? new DataColumns)->carried($data, $built, $published, dataLinkContext());
}

it('reads each spelling of a plain copy as the column it copies', function (string $data, array $built, array $carried): void {
    expect(dataCarried($data, $built))->toBe($carried);
})->with([
    // `from($van)` reads each property from the attribute of its name; `colour` is an accessor's output.
    'from() a model' => [VanData::class, [DataConstruction::model(Van::class)], ['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']]],
    // The constructor puts each argument in the promoted property of its name, or of its position.
    'the constructor, by name' => [VanData::class, [DataConstruction::arguments(['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate'], 'colour' => [Van::class, 'colour']])], ['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']]],
    'the constructor, by position' => [VanData::class, [DataConstruction::arguments([[Van::class, 'id'], [Van::class, 'plate'], null])], ['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']]],
    'from() an array' => [VanData::class, [DataConstruction::payload(['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']])], ['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']]],
    // `getKey()` reads the primary key, whatever the argument is called.
    'a key read through getKey()' => [VanData::class, [DataConstruction::arguments(['id' => [Van::class, BodyColumns::PRIMARY_KEY]])], ['id' => [Van::class, 'id']]],
    // Two constructions agree on the key and not on the plate: one copies it, the other copies nothing.
    'what every construction agrees on' => [VanData::class, [DataConstruction::model(Van::class), DataConstruction::arguments(['id' => [Van::class, 'id'], 'plate' => null])], ['id' => [Van::class, 'id']]],
    'a construction the walk could not read' => [VanData::class, [DataConstruction::model(Van::class), DataConstruction::unknown()], []],
    'from() a class that is not there' => [VanData::class, [DataConstruction::model('Docuccino\\Laravel\\Tests\\Fixtures\\ResponseLinks\\Missing')], []],
]);

it('reads the names laravel-data reads and writes each property under', function (string $data, array $built, array $carried, ?string $globalInput = null, ?string $globalOutput = null): void {
    $reflector = new DataClassReflector($globalInput, $globalOutput);
    $columns = new DataColumns(reflector: $reflector, globalInputMapper: $globalInput, globalOutputMapper: $globalOutput);

    expect(dataCarried($data, $built, columns: $columns, reflector: $reflector))->toBe($carried);
})->with([
    // The class snake-cases `fleetNumber` both ways; the plate is read from `plate` and sent as `registration`.
    'class and property maps, from() a model' => [FleetVanData::class, [DataConstruction::model(Van::class)], ['id' => [Van::class, 'id'], 'fleet_number' => [Van::class, 'fleet_number'], 'registration' => [Van::class, 'plate']]],
    // A payload key fills the property whose input name it is.
    'a mapped input name, from() an array' => [FleetVanData::class, [DataConstruction::payload(['plate' => [Van::class, 'plate']])], ['registration' => [Van::class, 'plate']]],
    // The constructor takes the property's own name, and the output map still renames it.
    'a mapped output name, by the constructor' => [FleetVanData::class, [DataConstruction::arguments([[Van::class, BodyColumns::PRIMARY_KEY], [Van::class, 'fleet_number'], [Van::class, 'plate']])], ['id' => [Van::class, 'id'], 'fleet_number' => [Van::class, 'fleet_number'], 'registration' => [Van::class, 'plate']]],
    // Camel-cased in, `fleetNumber` is snake-cased again by the model before it reads the attribute.
    'a model snake-casing the name it is asked for' => [CamelVanData::class, [DataConstruction::model(Van::class)], ['id' => [Van::class, 'id'], 'fleetNumber' => [Van::class, 'fleet_number']]],
    // A global strategy decides a property's name before its class's map does, which the published schema
    // does not mirror: the schema's `fleetNumber` is a member laravel-data sends as `fleet_number`.
    'a global strategy over a class map' => [CamelVanData::class, [DataConstruction::model(Van::class)], ['id' => [Van::class, 'id']], 'Spatie\\LaravelData\\Mappers\\SnakeCaseMapper', 'Spatie\\LaravelData\\Mappers\\SnakeCaseMapper'],
    // What an application's own mapper does is code this does not run, and nor is one handed over as an object.
    'a mapper the application wrote' => [OwnMapperVanData::class, [DataConstruction::model(Van::class)], ['id' => [Van::class, 'id']]],
    'a mapper handed over as an object' => [HandedMapperVanData::class, [DataConstruction::model(Van::class)], ['id' => [Van::class, 'id']]],
    // A dotted input name reads a nested key, which neither a model attribute nor a flat payload has.
    'a nested input name, from() a model' => [NestedInputVanData::class, [DataConstruction::model(Van::class)], ['id' => [Van::class, 'id']]],
    'a nested input name, from() an array' => [NestedInputVanData::class, [DataConstruction::payload(['id' => [Van::class, 'id'], 'registration.plate' => [Van::class, 'plate']])], ['id' => [Van::class, 'id']]],
]);

/**
 * The schema publishes what its own reading of the names says. Where laravel-data sends a property under
 * another name, a member the schema publishes under that name is some other property's, or nothing sent.
 */
it('carries a member only where the schema names the property as laravel-data does', function (): void {
    $snake = 'Spatie\\LaravelData\\Mappers\\SnakeCaseMapper';
    $columns = new DataColumns(reflector: new DataClassReflector(null, $snake), globalOutputMapper: $snake);

    expect(dataCarried(CamelVanData::class, [DataConstruction::model(Van::class)], ['id', 'fleet_number', 'fleetNumber'], $columns))->toBe(['id' => [Van::class, 'id']]);
});

it('reads the attribute under the very name it is asked for where the model does not snake-case it', function (): void {
    expect(dataCarried(LiteralVanData::class, [DataConstruction::model(LiteralVan::class)]))
        ->toBe(['id' => [LiteralVan::class, 'id'], 'fleetNumber' => [LiteralVan::class, 'fleetNumber']]);
});

it('carries nothing that is not a plain copy of the column', function (string $data, array $built, array $carried): void {
    expect(dataCarried($data, $built))->toBe($carried);
})->with([
    // A cast, a transformer, a computed, hidden, lazy or nullable property, or one typed for anything but
    // a key, may send something other than the column.
    'cast, transformed, computed, hidden, lazy, nullable or mistyped' => [PaintedVanData::class, [DataConstruction::model(Van::class)], []],
    'the same handed to the constructor' => [PaintedVanData::class, [DataConstruction::arguments(['plate' => [Van::class, 'plate'], 'id' => [Van::class, 'id'], 'model_name' => [Van::class, 'model_name'], 'make' => [Van::class, 'make'], 'mileage' => [Van::class, 'mileage'], 'weight' => [Van::class, 'weight']])], []],
    // A writable property is the column only until something sets it again, which may be the action
    // after it built the object: the walk does not follow every write.
    'a writable property, through from()' => [MutableVanData::class, [DataConstruction::model(Van::class)], []],
    'a writable property, by the constructor' => [MutableVanData::class, [DataConstruction::arguments(['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']])], []],
    // With no constructor, `from()` sets each property from outside, which only a writable property allows;
    // `new` hands nothing to any of them.
    'from() a class with no constructor' => [BareVanData::class, [DataConstruction::model(Van::class)], []],
    'the constructor of a class with none' => [BareVanData::class, [DataConstruction::arguments(['id' => [Van::class, 'id']])], []],
    // A plain parameter is handed to the constructor body, not to the property of its name.
    'a parameter that is not promoted' => [UnpromotedVanData::class, [DataConstruction::arguments(['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']])], ['id' => [Van::class, 'id']]],
    // A class making every property lazy sends each only on request.
    'lazy by its class' => [LazyVanData::class, [DataConstruction::model(Van::class)], []],
    // Its own `toArray()` decides what goes out under each name.
    'a class that writes its own serialised form' => [SerialisingVanData::class, [DataConstruction::model(Van::class)], []],
    'a constructor that does work' => [ConstructingVanData::class, [DataConstruction::arguments(['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']])], []],
    // `from()` falls back to the default where it reads nothing; the constructor was handed the column.
    'a default, through from()' => [DefaultedVanData::class, [DataConstruction::model(Van::class)], ['id' => [Van::class, 'id']]],
    'a default, by the constructor' => [DefaultedVanData::class, [DataConstruction::arguments(['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']])], ['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']]],
    // `from()` calls a creation method that accepts the van in place of copying; the constructor does not.
    'a creation method of its own, through from()' => [MadeVanData::class, [DataConstruction::model(Van::class)], []],
    'a creation method of its own, by the constructor' => [MadeVanData::class, [DataConstruction::arguments(['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']])], ['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']]],
    'normalizers of its own' => [NormalisingVanData::class, [DataConstruction::model(Van::class)], []],
    // Its own `from()` builds the object from whatever it likes; the constructor is still laravel-data's.
    'a from() of its own, through it' => [OverridingVanData::class, [DataConstruction::model(Van::class)], []],
    'a from() of its own, by the constructor' => [OverridingVanData::class, [DataConstruction::arguments(['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']])], ['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']]],
    // `collect()` is read as `from()` on each item, so one of its own stands in the same way.
    'a collect() of its own' => [CollectingVanData::class, [DataConstruction::model(Van::class)], []],
    // A column under an accessor, a cast or a date is not the value the route matches.
    'an accessor, a date cast' => [VanData::class, [DataConstruction::arguments(['id' => [Van::class, 'registered_at'], 'plate' => [Van::class, 'colour']])], []],
    // A property the model declares is read in place of the attribute of its name.
    'a column a model property shares its name with' => [VanData::class, [DataConstruction::arguments(['id' => [Van::class, 'id'], 'plate' => [Van::class, 'timestamps']])], ['id' => [Van::class, 'id']]],
    // A loaded relation of the column's name is what laravel-data hands over.
    'a column a relation shares its name with' => [VanData::class, [DataConstruction::arguments(['id' => [Van::class, 'id'], 'plate' => [Van::class, 'depot']])], ['id' => [Van::class, 'id']]],
    // A model that reads its own attributes may hand back anything under a column's name.
    'a model reading its own attributes' => [VanData::class, [DataConstruction::model(TranslatedVan::class)], []],
    // A copy of another model's column is that model's, not the van's.
    'another model' => [VanData::class, [DataConstruction::arguments(['id' => [Parcel::class, 'id']])], ['id' => [Parcel::class, 'id']]],
]);

/**
 * The schema keeps the configured envelope where it cannot tell whether the class takes it off, so the
 * member may sit under `data` or at the root. A pointer into either could read nothing.
 */
it('carries nothing from a class whose envelope is not settled', function (): void {
    expect(dataCarried(UnsettledVanData::class, [DataConstruction::model(Van::class)], columns: new DataColumns(wrap: new WrapResolver('data'))))->toBe([])
        ->and(dataCarried(UnsettledVanData::class, [DataConstruction::model(Van::class)]))->toBe(['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']]);
});

it('carries only a member the schema publishes', function (): void {
    expect(dataCarried(VanData::class, [DataConstruction::model(Van::class)], ['plate']))->toBe(['plate' => [Van::class, 'plate']])
        ->and(dataCarried(VanData::class, []))->toBe([]);
});

it('reads from() only where the normalizer that takes the payload is laravel-data\'s own, and no global cast or transformer stands in', function (array $settings, array $carried): void {
    expect(dataCarried(VanData::class, [DataConstruction::model(Van::class), DataConstruction::payload(['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']])], columns: new DataColumns(...$settings)))->toBe($carried);
})->with([
    // A model is an Arrayable: put first, its normalizer hands laravel-data the serialised array.
    'a model read as its array' => [['normalizers' => ['Spatie\\LaravelData\\Normalizers\\ArrayableNormalizer', 'Spatie\\LaravelData\\Normalizers\\ModelNormalizer']], []],
    'a normalizer the application wrote' => [['normalizers' => ['App\\Normalizers\\Mine', 'Spatie\\LaravelData\\Normalizers\\ModelNormalizer', 'Spatie\\LaravelData\\Normalizers\\ArrayNormalizer']], []],
    'laravel-data\'s own order' => [['normalizers' => ['Spatie\\LaravelData\\Normalizers\\ObjectNormalizer', 'Spatie\\LaravelData\\Normalizers\\ModelNormalizer', 'Spatie\\LaravelData\\Normalizers\\ArrayNormalizer']], ['id' => [Van::class, 'id'], 'plate' => [Van::class, 'plate']]],
    'a global cast for strings' => [['castTypes' => ['string']], ['id' => [Van::class, 'id']]],
    'a global transformer for integers' => [['transformerTypes' => ['int']], ['plate' => [Van::class, 'plate']]],
]);

it('sends a column as stored only where the model reads its attributes as the framework does', function (): void {
    $columns = new BodyColumns;

    expect($columns->sentAsStored(Van::class, 'plate', dataLinkContext()))->toBe('plate')
        ->and($columns->sentAsStored(TranslatedVan::class, 'plate', dataLinkContext()))->toBeNull();
});

/**
 * Whether a property is a plain copy is a fact of its class, not of a construction or an operation, so a
 * class costs its size once however often it is built. Rereading every constructor parameter for every
 * property of every construction takes seconds here; reading each once takes a fraction of one.
 */
it('reads a wide class built many times in time linear in its size', function (): void {
    $width = 2000;
    $class = 'Docuccino\\Laravel\\Tests\\Fixtures\\ResponseLinks\\Data\\WideVanData';
    // Written to a file, because the constructor's body is read from the one it is declared in.
    $file = sys_get_temp_dir().'/WideVanData.php';
    $properties = implode(', ', array_map(static fn (int $i): string => 'public readonly string $p'.$i, range(1, $width)));
    file_put_contents($file, '<?php namespace Docuccino\\Laravel\\Tests\\Fixtures\\ResponseLinks\\Data; final class WideVanData extends \\Spatie\\LaravelData\\Data { public function __construct('.$properties.') {} }');
    require_once $file;
    $published = array_map(static fn (int $i): string => 'p'.$i, range(1, $width));

    try {
        $started = hrtime(true);
        $carried = (new DataColumns)->carried($class, array_fill(0, 20, DataConstruction::model(Van::class)), $published, dataLinkContext());
        $seconds = (hrtime(true) - $started) / 1e9;
    } finally {
        unlink($file);
    }

    expect($carried)->toHaveCount($width)
        ->and($seconds)->toBeLessThan(3.0);
});
