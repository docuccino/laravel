<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Laravel\Integrations\ApiResources\WrappedCollectionVisitor;
use Docuccino\Laravel\Integrations\ApiResources\WrappedResource;
use Docuccino\Laravel\Tests\Fixtures\Collections\KeyingCollection;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Which walks prove a collection wraps a plain list: every construction of THAT collection handed an array,
 * the base collection or an Eloquent one, and at least one seen. A paginator, a type it cannot read, a
 * spread it cannot place, or a construction it never saw proves nothing.
 */
beforeEach(function (): void {
    $this->collection = 'App\\Http\\Resources\\GazetteCollection';

    // Walks `$source` with every expression typed by its printed form, as the engine's scope would type it.
    $this->walk = function (string $source, array $types): WrappedCollectionVisitor {
        $scope = new class($types) implements TypeScope
        {
            /** @param  array<string, DType>  $types */
            public function __construct(private readonly array $types) {}

            public function typeOf(Node\Expr $expr): DType
            {
                return $this->types[(new Standard)->prettyPrintExpr($expr)] ?? new UnknownT('untyped');
            }

            public function constantValueOf(Node\Expr $expr): ?ConstValue
            {
                return null;
            }

            public function location(Node $node): SourceLocation
            {
                return new SourceLocation('test.php', $node->getStartLine());
            }
        };

        $visitor = new WrappedCollectionVisitor($this->collection);
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse("<?php\nnamespace App\\Http\\Resources;\n".$source) ?? [];
        $ast = (new NodeTraverser(new NameResolver))->traverse($ast);
        (new NodeTraverser(new class($visitor, $scope) extends NodeVisitorAbstract
        {
            public function __construct(private readonly WrappedCollectionVisitor $visitor, private readonly TypeScope $scope) {}

            public function enterNode(Node $node): ?int
            {
                $this->visitor->enterNode($node, $this->scope);

                return null;
            }
        }))->traverse($ast);

        return $visitor;
    };
});

it('reads what each construction of the collection wraps', function (string $source, array $types, bool $plain): void {
    $collection = new ClassT('App\\Http\\Resources\\GazetteCollection');
    $types = array_map(static fn (DType|string $type): DType => $type === 'collection' ? $collection : $type, $types);

    expect(($this->walk)($source, $types)->wrapsPlainList())->toBe($plain);
})->with([
    'an Eloquent collection to collection()' => ['return GazetteResource::collection($users);', [
        '\\App\\Http\\Resources\\GazetteResource::collection($users)' => 'collection',
        '$users' => new ClassT('Illuminate\\Database\\Eloquent\\Collection'),
    ], true],
    'a model\'s own Eloquent collection, taken to the base like any' => ['return GazetteResource::collection($users);', [
        '\\App\\Http\\Resources\\GazetteResource::collection($users)' => 'collection',
        '$users' => new ClassT(KeyingCollection::class),
    ], true],
    'the base collection' => ['return GazetteResource::collection($users);', [
        '\\App\\Http\\Resources\\GazetteResource::collection($users)' => 'collection',
        '$users' => new ClassT(WrappedResource::PLAIN),
    ], true],
    'a lazy collection, which stays one' => ['return GazetteResource::collection($users);', [
        '\\App\\Http\\Resources\\GazetteResource::collection($users)' => 'collection',
        '$users' => new ClassT('Illuminate\\Support\\LazyCollection'),
    ], false],
    'a list' => ['return GazetteResource::collection($users);', [
        '\\App\\Http\\Resources\\GazetteResource::collection($users)' => 'collection',
        '$users' => new ListT(ScalarT::int()),
    ], true],
    'a keyed array' => ['return GazetteResource::collection($users);', [
        '\\App\\Http\\Resources\\GazetteResource::collection($users)' => 'collection',
        '$users' => new MapT(ScalarT::string(), ScalarT::int()),
    ], true],
    'an array shape' => ['return GazetteResource::collection($users);', [
        '\\App\\Http\\Resources\\GazetteResource::collection($users)' => 'collection',
        '$users' => new ArrayShapeT([new ArrayShapeField('a', ScalarT::int())]),
    ], true],
    'an object cast, which is no list' => ['return GazetteResource::collection($users);', [
        '\\App\\Http\\Resources\\GazetteResource::collection($users)' => 'collection',
        '$users' => new ArrayShapeT([new ArrayShapeField('a', ScalarT::int())], isObject: true),
    ], false],
    'a paginator' => ['return GazetteResource::collection($page);', [
        '\\App\\Http\\Resources\\GazetteResource::collection($page)' => 'collection',
        '$page' => new ClassT('Illuminate\\Pagination\\LengthAwarePaginator'),
    ], false],
    'something it cannot type' => ['return GazetteResource::collection($users);', [
        '\\App\\Http\\Resources\\GazetteResource::collection($users)' => 'collection',
    ], false],
    'a receiver transformed into the collection' => ['return $users->toResourceCollection(GazetteResource::class);', [
        '$users->toResourceCollection(\\App\\Http\\Resources\\GazetteResource::class)' => 'collection',
        '$users' => new ClassT('Illuminate\\Database\\Eloquent\\Collection'),
    ], true],
    'the collection constructed' => ['return new GazetteCollection($users, GazetteResource::class);', [
        'new \\App\\Http\\Resources\\GazetteCollection($users, \\App\\Http\\Resources\\GazetteResource::class)' => 'collection',
        '$users' => new ClassT('Illuminate\\Database\\Eloquent\\Collection'),
    ], true],
    'the collection made' => ['return GazetteCollection::make($users);', [
        '\\App\\Http\\Resources\\GazetteCollection::make($users)' => 'collection',
        '$users' => new ClassT('Illuminate\\Database\\Eloquent\\Collection'),
    ], true],
    'the resource named rather than placed' => ['return new GazetteCollection(collects: GazetteResource::class, resource: $users);', [
        'new \\App\\Http\\Resources\\GazetteCollection(collects: \\App\\Http\\Resources\\GazetteResource::class, resource: $users)' => 'collection',
        '$users' => new ClassT('Illuminate\\Database\\Eloquent\\Collection'),
    ], true],
    'a spread, whose first argument is anything' => ['return GazetteCollection::make(...$users);', [
        '\\App\\Http\\Resources\\GazetteCollection::make(...$users)' => 'collection',
        '$users' => new ClassT('Illuminate\\Database\\Eloquent\\Collection'),
    ], false],
    'another collection, which says nothing of this one' => ['return OtherResource::collection($users);', [
        '\\App\\Http\\Resources\\OtherResource::collection($users)' => new ClassT('App\\Http\\Resources\\OtherCollection'),
        '$users' => new ClassT('Illuminate\\Database\\Eloquent\\Collection'),
    ], false],
    'one plain construction beside a paged one' => ['$a = GazetteResource::collection($users); return GazetteResource::collection($page);', [
        '\\App\\Http\\Resources\\GazetteResource::collection($users)' => 'collection',
        '\\App\\Http\\Resources\\GazetteResource::collection($page)' => 'collection',
        '$users' => new ClassT('Illuminate\\Database\\Eloquent\\Collection'),
        '$page' => new ClassT('Illuminate\\Pagination\\CursorPaginator'),
    ], false],
    'no construction at all' => ['return $collection;', ['$collection' => 'collection'], false],
]);

it('asks to descend into every call, so a collection a helper builds is seen', function (): void {
    $visitor = new WrappedCollectionVisitor('App\\Http\\Resources\\GazetteCollection');
    $scope = new class implements TypeScope
    {
        public function typeOf(Node\Expr $expr): DType
        {
            return new UnknownT('untyped');
        }

        public function constantValueOf(Node\Expr $expr): ?ConstValue
        {
            return null;
        }

        public function location(Node $node): SourceLocation
        {
            return new SourceLocation('test.php');
        }
    };

    expect($visitor->enterNode(new Node\Expr\MethodCall(new Node\Expr\Variable('repo'), 'list'), $scope))->toBeTrue()
        ->and($visitor->enterNode(new Node\Expr\StaticCall(new Node\Name('Repo'), 'list'), $scope))->toBeTrue()
        ->and($visitor->enterNode(new Node\Expr\Variable('users'), $scope))->toBeFalse();
});
