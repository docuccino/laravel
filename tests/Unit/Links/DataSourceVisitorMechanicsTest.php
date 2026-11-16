<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Laravel\Links\BodyColumns;
use Docuccino\Laravel\Links\DataConstruction;
use Docuccino\Laravel\Links\DataSourceVisitor;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\FleetVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\VanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Van;
use Docuccino\Laravel\Tests\Support\StubTraceScope;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Spatie\LaravelData\Contracts\AppendableData;
use Spatie\LaravelData\Data;

/**
 * The spellings the visitor reads a Data construction from, and the ones it does not, driven over parsed
 * code with stand-in types. Which types the analyser hands it is the real engine's half
 * (`Feature/DataSourceVisitorTest`); this is what it makes of them.
 *
 * @return list<array{kind: string, model: ?string, sources: array<int|string, mixed>}>|null
 */
function dataConstructionsIn(string $php): ?array
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$php.';') ?? [];
    $ast = (new NodeTraverser(new NameResolver))->traverse($ast);
    $visitor = new DataSourceVisitor;
    $scope = new StubTraceScope(new ClassT('stdClass'), variableTypes: [
        'van' => new ClassT(Van::class),
        'vans' => new ClassT('Illuminate\\Database\\Eloquent\\Collection', [ScalarT::int(), new ClassT(Van::class)]),
        'listed' => new ListT(new ClassT(Van::class)),
        'plates' => new ListT(ScalarT::string()),
        'bag' => new ClassT('stdClass', [new ClassT(Van::class)]),
        'data' => new ClassT(VanData::class),
        'fleet' => new ClassT(FleetVanData::class),
        'meta' => new ClassT(Data::class),
        'appendable' => new ClassT(AppendableData::class),
        'either' => UnionT::of([new ClassT(VanData::class), new ClassT(FleetVanData::class)]),
    ]);

    (new NodeTraverser(new class($visitor, $scope) extends NodeVisitorAbstract
    {
        public function __construct(private readonly DataSourceVisitor $visitor, private readonly StubTraceScope $scope) {}

        public function enterNode(Node $node): ?int
        {
            $this->visitor->enterNode($node, $this->scope);

            return null;
        }
    }))->traverse($ast);

    $built = $visitor->constructionsOf(VanData::class);

    return $built === null ? null : array_map(static fn (DataConstruction $c): array => ['kind' => $c->kind, 'model' => $c->model, 'sources' => $c->sources], $built);
}

it('reads a Data construction only where it can name what each value is', function (string $php, ?array $built): void {
    expect(dataConstructionsIn($php))->toBe($built);
})->with([
    'collect() an Eloquent collection of models' => ['\\'.VanData::class.'::collect($vans)', [['kind' => 'model', 'model' => Van::class, 'sources' => []]]],
    'collect() a list of models' => ['\\'.VanData::class.'::collect($listed)', [['kind' => 'model', 'model' => Van::class, 'sources' => []]]],
    'collect() a list of strings' => ['\\'.VanData::class.'::collect($plates)', [['kind' => 'unknown', 'model' => null, 'sources' => []]]],
    // A class that is not iterable says nothing about items, whatever its type arguments.
    'collect() something that is not a collection' => ['\\'.VanData::class.'::collect($bag)', [['kind' => 'unknown', 'model' => null, 'sources' => []]]],
    'from() unpacked' => ['\\'.VanData::class.'::from(...$vans)', [['kind' => 'unknown', 'model' => null, 'sources' => []]]],
    'from() nothing' => ['\\'.VanData::class.'::from()', [['kind' => 'unknown', 'model' => null, 'sources' => []]]],
    'from() two payloads' => ['\\'.VanData::class.'::from($van, $van)', [['kind' => 'unknown', 'model' => null, 'sources' => []]]],
    // Any other static call on the class may build one some other way.
    'another static call' => ['\\'.VanData::class.'::optional($van)', [['kind' => 'unknown', 'model' => null, 'sources' => []]]],
    'the constructor, unpacked' => ['new \\'.VanData::class.'(...$vans)', [['kind' => 'unknown', 'model' => null, 'sources' => []]]],
    'an array spreading another' => ['\\'.VanData::class.'::from([\'id\' => $van->id, ...$vans])', [['kind' => 'unknown', 'model' => null, 'sources' => []]]],
    'an array with a key that is not a name' => ['\\'.VanData::class.'::from([0 => $van->id])', [['kind' => 'unknown', 'model' => null, 'sources' => []]]],
    // The keys, read through the methods that hand them out; any other method's answer is no column.
    'keys read through the model' => ['new \\'.VanData::class.'(id: $van->getKey(), plate: $van->getRouteKey(), colour: $van->colour())', [['kind' => 'arguments', 'model' => null, 'sources' => [
        'id' => [Van::class, BodyColumns::PRIMARY_KEY],
        'plate' => [Van::class, BodyColumns::ROUTE_KEY],
        'colour' => null,
    ]]]],
    'a key read off something that is not a model' => ['new \\'.VanData::class.'(id: $other->getKey())', [['kind' => 'arguments', 'model' => null, 'sources' => ['id' => null]]]],
    'an instance method called on one' => ['\\'.VanData::class.'::from($van); $data->additional([])', [['kind' => 'model', 'model' => Van::class, 'sources' => []], ['kind' => 'unknown', 'model' => null, 'sources' => []]]],
    // A receiver typed on a class or interface the Data class extends may be one of its objects: a helper
    // typed on the base class, or on a template bound to it, changes it as surely.
    'an instance method called through the base class' => ['\\'.VanData::class.'::from($van); $meta->additional([])', [['kind' => 'model', 'model' => Van::class, 'sources' => []], ['kind' => 'unknown', 'model' => null, 'sources' => []]]],
    'an instance method called through an interface it implements' => ['\\'.VanData::class.'::from($van); $appendable->additional([])', [['kind' => 'model', 'model' => Van::class, 'sources' => []], ['kind' => 'unknown', 'model' => null, 'sources' => []]]],
    'an instance method called on either of two Data classes' => ['\\'.VanData::class.'::from($van); $either->additional([])', [['kind' => 'model', 'model' => Van::class, 'sources' => []], ['kind' => 'unknown', 'model' => null, 'sources' => []]]],
    // Another Data class's object is never this one, nor is a model.
    'an instance method called on another Data class' => ['\\'.VanData::class.'::from($van); $fleet->additional([]); $van->save()', [['kind' => 'model', 'model' => Van::class, 'sources' => []]]],
    'nothing built' => ['$van->save()', null],
]);
