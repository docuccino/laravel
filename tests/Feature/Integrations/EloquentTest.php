<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\BuiltIn\EnumSchema;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\Eloquent\AccessorReader;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use Docuccino\Laravel\Integrations\Eloquent\ModelSchema;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Astrolabe;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Blank;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Boutique;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Chronicle;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Consignment;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Coupon;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\CustomCaster;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Daybook;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Depot;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Emblem;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Gadget;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Hourglass;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Invoice;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Ledger;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Merchant;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Metronome;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Persona;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Post;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Sandglass;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Showcase;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Signpost;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Strongbox;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Waterclock;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Waybill;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Widget;
use Workbench\App\Enums\WidgetStatus;

/**
 * The Eloquent model schema integration: engine-reported columns refined by the model's
 * visible/hidden/appends and class-level #[Hidden], with casts fixing datetime formats and routing
 * enum casts through the Enum integration.
 */
function eloquentEngine(): StubTypeEngine
{
    return new StubTypeEngine(classes: [
        Persona::class => new ClassMetadata(Persona::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('email', ScalarT::string()),
        ]),
        Widget::class => new ClassMetadata(Widget::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('name', ScalarT::string()),
            new PropertyMetadata('password', ScalarT::string()),
            new PropertyMetadata('token', ScalarT::string()),
            new PropertyMetadata('created_at', UnionT::of([ScalarT::string(), new NullT])),
            // is_active is typed string by the engine, but the boolean cast wins.
            new PropertyMetadata('is_active', ScalarT::string()),
            new PropertyMetadata('status', ScalarT::string()),
            new PropertyMetadata('meta', ScalarT::string()),
        ]),
        Gadget::class => new ClassMetadata(Gadget::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('name', ScalarT::string()),
            new PropertyMetadata('secret', ScalarT::string()),
        ]),
        Vault::class => new ClassMetadata(Vault::class, [
            new PropertyMetadata('id', ScalarT::string()),
            new PropertyMetadata('label', ScalarT::string()),
        ]),
        Invoice::class => new ClassMetadata(Invoice::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('amount', ScalarT::int()),
            new PropertyMetadata('issued_at', UnionT::of([ScalarT::string(), new NullT])),
            new PropertyMetadata('meta', ScalarT::string()),
            new PropertyMetadata('status', ScalarT::string()),
        ]),
        Consignment::class => new ClassMetadata(Consignment::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('status', UnionT::of([ScalarT::string(), new NullT])),
            new PropertyMetadata('manifest', UnionT::of([ScalarT::string(), new NullT])),
            new PropertyMetadata('sealed_at', UnionT::of([ScalarT::string(), new NullT])),
        ]),
        Boutique::class => new ClassMetadata(Boutique::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('sku', ScalarT::string()),
        ]),
        Post::class => new ClassMetadata(Post::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('title', ScalarT::string()),
        ]),
        Merchant::class => new ClassMetadata(Merchant::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('name', ScalarT::string()),
        ]),
        // The ide-helper tag types the date column; `filed_on` carries none, so the same cast is read
        // from the docblock column path and from the floor.
        Astrolabe::class => new ClassMetadata(Astrolabe::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('title', ScalarT::string()),
            new PropertyMetadata('sighted_on', new ClassT('Illuminate\\Support\\Carbon')),
        ]),
        Metronome::class => new ClassMetadata(Metronome::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('title', ScalarT::string()),
        ]),
        Chronicle::class => new ClassMetadata(Chronicle::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('title', ScalarT::string()),
        ]),
        Strongbox::class => new ClassMetadata(Strongbox::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('label', ScalarT::string()),
            new PropertyMetadata('combination', ScalarT::string()),
        ]),
        Showcase::class => new ClassMetadata(Showcase::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('name', ScalarT::string()),
            new PropertyMetadata('tally', ScalarT::string()),
        ]),
        Daybook::class => new ClassMetadata(Daybook::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('title', ScalarT::string()),
        ]),
        Signpost::class => new ClassMetadata(Signpost::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('label', ScalarT::string()),
        ]),
        // The tags `php artisan ide-helper:models` writes: every column, dates included, typed by the
        // Carbon class the attribute holds.
        Waterclock::class => new ClassMetadata(Waterclock::class, [
            new PropertyMetadata('posted_at', new ClassT('Carbon\\CarbonImmutable')),
            new PropertyMetadata('sealed_at', ScalarT::string()),
        ]),
        Sandglass::class => new ClassMetadata(Sandglass::class, [
            new PropertyMetadata('posted_at', new ClassT('Carbon\\CarbonImmutable')),
        ]),
        Hourglass::class => new ClassMetadata(Hourglass::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('created_at', new ClassT('Illuminate\\Support\\Carbon')),
            new PropertyMetadata('updated_at', new ClassT('Illuminate\\Support\\Carbon')),
            new PropertyMetadata('posted_at', new ClassT('Carbon\\CarbonImmutable')),
        ]),
    ], callables: (static function (): array {
        // Accessor / custom-caster / relation return types scripted so the in-process mapper test drives
        // the same shapes the real engine recovers; the recovery half is proven out-of-process in
        // RealEngineIntegrationsTest. Keyed by CallableRef::symbol().
        $loc = new SourceLocation('');
        $returning = static fn (DType $type): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite($type, $loc)]);

        return [
            Sandglass::class.'::getPostedAtAttribute' => $returning(new ClassT('Carbon\\CarbonImmutable')),
            Boutique::class.'::getFullLabelAttribute' => $returning(ScalarT::string()),
            Boutique::class.'::getOptionsAttribute' => $returning(ScalarT::string()),
            CustomCaster::class.'::get' => $returning(ScalarT::string()),
            Boutique::class.'::posts' => $returning(new ClassT('Illuminate\\Database\\Eloquent\\Relations\\HasMany', [new ClassT(Post::class)])),
            Boutique::class.'::owner' => $returning(new ClassT('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo', [new ClassT(Merchant::class)])),
            Strongbox::class.'::getPublicNoteAttribute' => $returning(ScalarT::string()),
            Strongbox::class.'::getInternalNoteAttribute' => $returning(ScalarT::string()),
            Strongbox::class.'::auditTrail' => $returning(new ClassT('Illuminate\\Database\\Eloquent\\Relations\\HasMany', [new ClassT(Post::class)])),
            Strongbox::class.'::strongRoom' => $returning(new ClassT('Illuminate\\Database\\Eloquent\\Relations\\HasMany', [new ClassT(Post::class)])),
            Strongbox::class.'::keeper' => $returning(new ClassT('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo', [new ClassT(Merchant::class)])),
            Showcase::class.'::getBadgeAttribute' => $returning(ScalarT::string()),
            Showcase::class.'::getRankingAttribute' => $returning(ScalarT::string()),
            Emblem::class.'::getBadgeAttribute' => $returning(ScalarT::string()),
            Depot::class.'::keeper' => $returning(new ClassT('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo', [new ClassT(Merchant::class)])),
        ];
    })());
}

function modelSchema(ClassT $type): array
{
    return modelRegistry($type)->schemas();
}

function modelRegistry(ClassT $type): ComponentRegistry
{
    $components = new ComponentRegistry;
    $converter = new SchemaConverter([new ModelSchema, new EnumSchema, ...DefaultTypeMappers::all()], eloquentEngine(), $components);
    $converter->toSchema($type);

    return $components;
}

it('builds a model schema honouring hidden, appends, and casts', function (): void {
    $registry = modelRegistry(new ClassT(Widget::class));
    $widget = $registry->schemas()['Widget'];

    // password ($hidden) and token (class-level #[Hidden]) are dropped; display_name ($appends) added;
    // updated_at is synthesised from the model's default timestamps (created_at is already a cast).
    expect(array_keys($widget['properties']))
        ->toBe(['id', 'name', 'created_at', 'is_active', 'status', 'meta', 'updated_at', 'display_name']);

    // datetime cast → date-time format, widened to admit null on the nullable column; boolean cast
    // overrides the engine's string type; array cast admits a JSON object or array.
    expect($widget['properties']['created_at'])->toBe(['type' => ['string', 'null'], 'format' => 'date-time'])
        ->and($widget['properties']['is_active'])->toBe(['type' => 'boolean'])
        ->and($widget['properties']['meta'])->toBe(['type' => ['array', 'object']]);

    // enum cast routes through the Enum integration, hoisted to a $ref'd component (backing values +
    // case descriptions live on the component).
    expect($widget['properties']['status'])->toBe(['$ref' => '#/components/schemas/WidgetStatus'])
        ->and($registry->schemas()['WidgetStatus']['enum'])->toBe(['draft', 'published', 'archived'])
        ->and($registry->schemas()['WidgetStatus'])->toHaveKey('x-enum-descriptions');

    // Every declared column is present in the payload, so all are required — a nullable column
    // (created_at) is required with a null-admitting type. The appended accessor stays optional.
    expect($widget['required'])->toBe(['id', 'name', 'created_at', 'is_active', 'status', 'meta', 'updated_at']);
});

it('synthesises timestamps + soft-delete columns and a uuid primary key', function (): void {
    $vault = modelSchema(new ClassT(Vault::class))['Vault'];

    // HasUuids overrides the key column to a string uuid; timestamps + SoftDeletes inject the columns
    // Laravel serialises for a persisted, soft-deletable model.
    expect($vault['properties']['id'])->toBe(['type' => 'string', 'format' => 'uuid'])
        ->and($vault['properties']['created_at'])->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and($vault['properties']['updated_at'])->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and($vault['properties']['deleted_at'])->toBe(['type' => ['string', 'null'], 'format' => 'date-time'])
        ->and($vault['required'])->toBe(['id', 'label', 'created_at', 'updated_at', 'deleted_at']);
});

/*
 * A cast describes the non-null shape only, so a nullable cast column has to CONTRIBUTE that shape to a
 * union rather than publish it in place of one. Two of the three fragments a cast can produce fold the
 * null into their own `type`; the third — an enum cast's `$ref` — cannot, and used to be handed back
 * untouched, publishing a schema that forbade the null the column really carries.
 */
it('keeps the null on every cast-fragment shape a nullable column can produce', function (string $column, array $expected): void {
    $consignment = modelSchema(new ClassT(Consignment::class))['Consignment'];

    expect($consignment['properties'][$column])->toBe($expected);
})->with([
    'an enum cast, whose $ref takes a branch' => ['status', [
        'anyOf' => [['$ref' => '#/components/schemas/WidgetStatus'], ['type' => 'null']],
    ]],
    'a json cast, whose type LIST gains a member' => ['manifest', ['type' => ['array', 'object', 'null']]],
    'a datetime cast, whose named type becomes a list' => ['sealed_at', ['type' => ['string', 'null'], 'format' => 'date-time']],
    // The soft-delete column beside them, synthesised rather than cast, and expressed the same way.
    'the synthesised soft-delete column' => ['deleted_at', ['type' => ['string', 'null'], 'format' => 'date-time']],
]);

it('expresses a nullable cast column in the shape the document asked for', function (string $column, array $expected): void {
    // Under the `anyof` policy every nullable member is a branch, cast columns included — a producer
    // that widened its own fragment expressed nullability in a shape the rest of the document did not.
    $components = new ComponentRegistry;
    (new SchemaConverter(
        [new ModelSchema, new EnumSchema, ...DefaultTypeMappers::all()],
        eloquentEngine(),
        $components,
        new RepresentationPolicy(nullable: 'anyof'),
    ))->toSchema(new ClassT(Consignment::class));

    expect($components->schemas()['Consignment']['properties'][$column])->toBe($expected);
})->with([
    'an enum cast' => ['status', ['anyOf' => [['$ref' => '#/components/schemas/WidgetStatus'], ['type' => 'null']]]],
    'a json cast' => ['manifest', ['anyOf' => [['type' => ['array', 'object']], ['type' => 'null']]]],
    'a datetime cast' => ['sealed_at', ['anyOf' => [['type' => 'string', 'format' => 'date-time'], ['type' => 'null']]]],
    'the synthesised soft-delete column' => ['deleted_at', ['anyOf' => [['type' => 'string', 'format' => 'date-time'], ['type' => 'null']]]],
]);

it('resolves the route-key schema for a bound model across every key kind', function (string $fqcn, array $expected): void {
    // The pure resolver a bound `{model}` path parameter uses (uuid/ulid/int/string), degrading to
    // integer for a non-model or unreflectable FQCN.
    expect((new EloquentModelReflector)->keySchemaFor($fqcn))->toBe($expected);
})->with([
    'HasUuids → string/uuid' => [Vault::class, ['type' => 'string', 'format' => 'uuid']],
    'HasUlids → string/ulid' => [Waybill::class, ['type' => 'string', 'format' => 'ulid']],
    'non-incrementing string key → plain string' => [Coupon::class, ['type' => 'string']],
    'default incrementing key → integer' => [Widget::class, ['type' => 'integer']],
    'a non-model FQCN degrades to integer' => ['Illuminate\\Http\\Request', ['type' => 'integer']],
]);

it('reflects timestamps, soft-delete, and primary-key facts', function (): void {
    $facts = (new EloquentModelReflector)->facts(Vault::class);

    expect($facts['timestamps'])->toBeTrue()
        ->and($facts['softDeletes'])->toBeTrue()
        ->and($facts['keyName'])->toBe('id')
        ->and($facts['keySchema'])->toBe(['type' => 'string', 'format' => 'uuid']);

    // A plain model has timestamps on by default but no soft-deletes and an integer key.
    $widgetFacts = (new EloquentModelReflector)->facts(Widget::class);
    expect($widgetFacts['softDeletes'])->toBeFalse()
        ->and($widgetFacts['keySchema'])->toBe(['type' => 'integer']);
});

it('reads the casts() method (Laravel 11+) and applies its casts to columns', function (): void {
    $facts = (new EloquentModelReflector)->facts(Invoice::class);

    // The casts() method's literal return is recovered — string casts and the enum ::class cast.
    expect($facts['casts'])->toBe([
        'issued_at' => 'datetime',
        'meta' => 'array',
        'status' => WidgetStatus::class,
    ]);

    $registry = modelRegistry(new ClassT(Invoice::class));
    $invoice = $registry->schemas()['Invoice'];

    // The recovered casts refine the columns: datetime (nullable), array→object|array, enum ($ref).
    expect($invoice['properties']['issued_at'])->toBe(['type' => ['string', 'null'], 'format' => 'date-time'])
        ->and($invoice['properties']['meta'])->toBe(['type' => ['array', 'object']])
        ->and($invoice['properties']['status'])->toBe(['$ref' => '#/components/schemas/WidgetStatus'])
        ->and($registry->schemas()['WidgetStatus']['enum'])->toBe(['draft', 'published', 'archived']);
});

/*
 * Every key the schema publishes goes through the one visibility reading the server uses. Laravel
 * filters columns, appends AND relations through `HasAttributes::getArrayableItems()`, so a name it
 * removes is a name the response never carries — whichever source contributed the key. A key published
 * past that reading is a field a generated client waits for and never receives, and, where the name was
 * deliberately hidden, a secret named in a public artifact.
 */
it('publishes only the keys the runtime serialises, whatever contributed them', function (string $fqcn, string $component, array $expected): void {
    expect(array_keys(modelSchema(new ClassT($fqcn))[$component]['properties']))->toBe($expected);
})->with([
    'a hidden column, one named by a class-level #[Hidden], and an append neither list touches' => [
        Widget::class, 'Widget', ['id', 'name', 'created_at', 'is_active', 'status', 'meta', 'updated_at', 'display_name'],
    ],
    // audit_trail is gone because `auditTrail` is in $hidden; strong_room stays because `strong_room`
    // is the SERIALISED spelling and the filter runs before relationsToArray() snake-cases the key.
    'a hidden append, a hidden eager-loaded relation, and the ones that survive both' => [
        Strongbox::class, 'Strongbox', ['id', 'label', 'public_note', 'strong_room', 'keeper'],
    ],
    'an allow-list with no deny-list beside it' => [Gadget::class, 'Gadget', ['id', 'name']],
    // `name` is in both lists: getArrayableItems() subtracts $hidden AFTER intersecting $visible, so
    // the deny-list wins. `ranking` is an append outside the allow-list, which never serialises either.
    'an allow-list narrowed further by a deny-list' => [Showcase::class, 'Showcase', ['id', 'badge']],
    // `nickname` is an Attribute accessor with no column and no $appends entry, so nothing serialises
    // it — addMutatedAttributesToArray() only mutates keys the attribute array already has.
    'an accessor that is neither a column nor an append' => [
        Boutique::class, 'Boutique', ['id', 'sku', 'options', 'tags', 'kinds', 'secret', 'full_label', 'posts', 'owner'],
    ],
    // The two models whose DATE columns the gate drops, which is why they publish no date attribute for
    // an override to have weakened — the quiet half of the notice table below.
    'timestamps a model turned off entirely' => [Signpost::class, 'Signpost', ['id', 'label']],
    'timestamps the model has and $hidden keeps out' => [Daybook::class, 'Daybook', ['id', 'title']],
]);

/*
 * The same class of defect from the other end: a model declares no PHP property for a column, but it
 * INHERITS six public ones from Illuminate\Database\Eloquent\Model, and class metadata reports them
 * beside the `@property` column tags (checked against the real ClassMetadataFactory, which returns
 * timestamps/incrementing/preventsLazyLoading/exists/wasRecentlyCreated/usesUniqueIds for every model).
 * `attributesToArray()` serialises none of them — it reads `$this->attributes`, the appends and the
 * relations, and a declared property is in none of the three — so publishing them promises a client six
 * booleans that never arrive, on every model in the document.
 */
it('never publishes the framework bookkeeping properties reported beside a model\'s columns', function (): void {
    $components = new ComponentRegistry;
    $engine = new StubTypeEngine(classes: [
        Post::class => new ClassMetadata(Post::class, [
            new PropertyMetadata('timestamps', ScalarT::bool()),
            new PropertyMetadata('incrementing', ScalarT::bool()),
            new PropertyMetadata('preventsLazyLoading', ScalarT::bool()),
            new PropertyMetadata('exists', ScalarT::bool()),
            new PropertyMetadata('wasRecentlyCreated', ScalarT::bool()),
            new PropertyMetadata('usesUniqueIds', ScalarT::bool()),
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('title', ScalarT::string()),
        ]),
    ]);

    (new SchemaConverter([new ModelSchema, ...DefaultTypeMappers::all()], $engine, $components))->toSchema(new ClassT(Post::class));

    expect(array_keys($components->schemas()['Post']['properties']))->toBe(['id', 'title']);
});

it('reads the bookkeeping names off the resolved Laravel rather than a list of its own', function (): void {
    // The names above are dropped because the framework declares them, so the reading has to keep
    // finding them: a reader that silently stopped matching would publish them all again.
    expect(EloquentModelReflector::frameworkProperties())
        ->toContain('timestamps', 'incrementing', 'preventsLazyLoading', 'exists', 'wasRecentlyCreated', 'usesUniqueIds')
        // A model's own columns are magic, so nothing else about this set should be growing quietly.
        ->and(count(EloquentModelReflector::frameworkProperties()))->toBeLessThan(12);
});

it('keeps a withheld key out of `required` as well, and types the append that survives', function (): void {
    $strongbox = modelSchema(new ClassT(Strongbox::class))['Strongbox'];

    // A key the response never carries cannot be one a client may rely on being there.
    expect($strongbox['required'])->toBe(['id', 'label', 'strong_room', 'keeper'])
        // The surviving append is still typed by its accessor, and the surviving relations still nest.
        ->and($strongbox['properties']['public_note'])->toBe(['type' => 'string'])
        ->and($strongbox['properties']['strong_room'])->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Post']])
        ->and($strongbox['properties']['keeper'])->toBe(['anyOf' => [['$ref' => '#/components/schemas/Merchant'], ['type' => 'null']]]);
});

it('reflects model facts without instantiating', function (): void {
    $facts = (new EloquentModelReflector)->facts(Widget::class);

    expect($facts['hidden'])->toBe(['password'])
        ->and($facts['classHidden'])->toBe(['token'])
        ->and($facts['appends'])->toBe(['display_name'])
        ->and($facts['casts'])->toHaveKey('created_at')
        ->and(EloquentModelReflector::isModel(Widget::class))->toBeTrue()
        ->and(EloquentModelReflector::isModel('Illuminate\\Database\\Eloquent\\Model'))->toBeFalse();
});

it('reflects the floor sources ($fillable, $dates) alongside casts', function (): void {
    $facts = (new EloquentModelReflector)->facts(Ledger::class);

    expect($facts['fillable'])->toBe(['reference', 'amount', 'notes'])
        ->and($facts['dates'])->toBe(['posted_at'])
        ->and($facts['casts'])->toBe(['amount' => 'integer', 'secret' => 'string']);
});

it('builds the column universe from the floor sources when the engine reports no columns', function (): void {
    // Ledger has no @property docblock, so eloquentEngine() reports no columns for it: the whole
    // schema comes from the floor union (casts keys, $dates, $fillable), with $hidden still filtering.
    $ledger = modelSchema(new ClassT(Ledger::class))['Ledger'];

    // Order: casts keys, then $dates, then $fillable-only names. `secret` ($hidden) is dropped.
    expect(array_keys($ledger['properties']))->toBe(['amount', 'posted_at', 'reference', 'notes'])
        ->and($ledger['properties'])->not->toHaveKey('secret');

    // A cast key is typed by its cast; a $dates entry is a date-time; a $fillable-only name is a
    // permissive `{}` at lowered confidence.
    expect($ledger['properties']['amount'])->toBe(['type' => 'integer'])
        ->and($ledger['properties']['posted_at'])->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and($ledger['properties']['reference'])->toBe([])
        ->and($ledger['properties']['notes'])->toBe([]);

    // Cast/date floor columns serialise (required); the untyped permissive ones stay optional.
    expect($ledger['required'])->toBe(['amount', 'posted_at']);
});

/**
 * The notice asserts what the document PUBLISHES ("documented as a bare object"), so it may only be
 * decided by the finished property set — appends, accessors and eager loads all add keys after the
 * column sources are exhausted (docs/design/defect-classes.md §"A diagnostic that asserts an outcome it
 * never reads"). Stated as the union of the two halves rather than a test each, so a late key source
 * that stopped counting cannot fall between them.
 */
it('raises the bare-object notice exactly where the finished schema publishes no property', function (string $fqcn, string $component, array $properties, bool $reported): void {
    $registry = modelRegistry(new ClassT($fqcn));
    $codes = array_map(static fn ($d): string => $d->code, $registry->diagnostics());

    expect($registry->schemas()[$component])->toHaveKey('type', 'object')
        ->and($registry->schemas()[$component]['properties'])->toBe($properties)
        ->and(in_array('eloquent.no-columns', $codes, true))->toBe($reported);
})->with([
    'no source yields a key at all' => [Blank::class, 'Blank', [], true],
    'an append is the one key' => [Emblem::class, 'Emblem', ['badge' => ['type' => 'string']], false],
    'an eager-loaded relation is the one key' => [Depot::class, 'Depot', [
        'keeper' => ['anyOf' => [['$ref' => '#/components/schemas/Merchant'], ['type' => 'null']]],
    ], false],
]);

it('discovers a model\'s classic and Attribute accessors via real reflection', function (): void {
    // Real reflection + php-parser over the idiomatic Boutique fixture, no stub: classic getters map to
    // snake-cased attribute names analysed by their own method; the Attribute accessor is located by the
    // line of its get closure (a closure ref, not a named method); framework getters are excluded.
    $accessors = (new AccessorReader)->read(Boutique::class);

    $byAttribute = [];
    foreach ($accessors as $accessor) {
        $byAttribute[$accessor['attribute']] = $accessor['ref'];
    }

    expect(array_keys($byAttribute))->toBe(['full_label', 'options', 'nickname'])
        ->and($byAttribute['full_label']->symbol())->toBe(Boutique::class.'::getFullLabelAttribute')
        ->and($byAttribute['options']->symbol())->toBe(Boutique::class.'::getOptionsAttribute');

    // The Attribute accessor is a line-located closure (no class/method), so the engine analyses the
    // get closure's return type — not the method's `Attribute` return type.
    $nickname = $byAttribute['nickname'];
    expect($nickname->isClosure())->toBeTrue()
        ->and($nickname->line)->toBeGreaterThan(0)
        ->and($nickname->class)->toBeNull()
        ->and($nickname->method)->toBeNull();
});

it('types appended accessors, overrides a column\'s cast with its accessor, and maps the As* casts', function (): void {
    $registry = modelRegistry(new ClassT(Boutique::class));
    $boutique = $registry->schemas()['Boutique'];

    // full_label is an appended accessor typed by getFullLabelAttribute() → string (was permissive {}).
    expect($boutique['properties']['full_label'])->toBe(['type' => 'string']);

    // options carries an `array` cast, but getOptionsAttribute(): string wins and the cast is skipped —
    // mirroring HasAttributes' mutate-then-cast precedence.
    expect($boutique['properties']['options'])->toBe(['type' => 'string']);

    // AsCollection → array; AsEnumCollection:Enum → an array whose items $ref the hoisted enum
    // component (routed through the Enum integration); the custom CastsAttributes caster → its get()
    // return type (string).
    expect($boutique['properties']['tags'])->toBe(['type' => 'array'])
        ->and($boutique['properties']['kinds']['type'])->toBe('array')
        ->and($boutique['properties']['kinds']['items'])->toBe(['$ref' => '#/components/schemas/WidgetStatus'])
        ->and($registry->schemas()['WidgetStatus']['enum'])->toBe(['draft', 'published', 'archived'])
        ->and($boutique['properties']['secret'])->toBe(['type' => 'string']);

    // The appended accessor stays optional; every cast column is required.
    expect($boutique['required'])->toBe(['id', 'sku', 'options', 'tags', 'kinds', 'secret', 'posts', 'owner']);
});

it('adds $with eager-loaded relations as nested model schemas (to-many array, to-one nullable ref)', function (): void {
    $registry = modelRegistry(new ClassT(Boutique::class));
    $boutique = $registry->schemas()['Boutique'];

    // posts (HasMany<Post>) → an array of the related model; owner (BelongsTo<Merchant>) → a nullable
    // reference. Both are eager-loaded, so present on every response (required).
    expect($boutique['properties']['posts'])->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Post']])
        ->and($boutique['properties']['owner'])->toBe(['anyOf' => [['$ref' => '#/components/schemas/Merchant'], ['type' => 'null']]]);

    // The related models are hoisted as their own components (depth-capped via the shared hoist).
    expect($registry->schemas())->toHaveKeys(['Post', 'Merchant']);
});

it('omits an eager-loaded relation whose related model it could not resolve, and says which', function (): void {
    // `$with` names a relation the document has to carry on every response, so a relation whose return
    // type the engine cannot read leaves a hole. Guessing an object there would be a shape a client
    // trusts and the server never sends — so the key is omitted and the reader is told where to annotate.
    $components = new ComponentRegistry;
    $engine = new StubTypeEngine(classes: [
        Boutique::class => new ClassMetadata(Boutique::class, [new PropertyMetadata('id', ScalarT::int())]),
    ]);

    (new SchemaConverter([new ModelSchema, new EnumSchema, ...DefaultTypeMappers::all()], $engine, $components))
        ->toSchema(new ClassT(Boutique::class));

    $unresolved = array_values(array_filter(
        $components->diagnostics(),
        static fn ($d): bool => $d->code === 'eloquent.unresolved-eager-load',
    ));

    expect(array_map(static fn ($d): string => $d->message, $unresolved))->toBe([
        'Could not resolve the related model of '.Boutique::class.'::posts() (declared in $with), so it is omitted from the schema.',
        'Could not resolve the related model of '.Boutique::class.'::owner() (declared in $with), so it is omitted from the schema.',
    ])
        ->and($components->schemas()['Boutique']['properties'] ?? [])->not->toHaveKeys(['posts', 'owner']);
});

/**
 * The date-serialisation notice as the union it has to be: a row per model in the population, carrying
 * whether the document that model produced actually lost a `format`. The override alone is not the
 * condition — it usually sits on a base every model extends — so the quiet rows are the ones that keep
 * the notice honest (docs/design/defect-classes.md §"A diagnostic that asserts an outcome it never
 * reads"). One test per reason left the two halves covering their own subsets and nothing stating which
 * models belong to which.
 */
it('reports a lost date format exactly where the document published one', function (string $fqcn, string $component, bool $reported): void {
    $registry = modelRegistry(new ClassT($fqcn));
    $codes = array_map(static fn ($d): string => $d->code, $registry->diagnostics());

    // Anti-vacuity: the model really was mapped, so a quiet row cannot pass by producing no schema.
    expect($registry->schemas())->toHaveKey($component)
        ->and(in_array('eloquent.custom-date-serialization', $codes, true))->toBe($reported);
})->with([
    // Loud: the override reached a date attribute the document carries.
    'an override the model declares, over a cast and the framework timestamps' => [Chronicle::class, 'Chronicle', true],
    // The `@property` loop publishes a column before any date source is consulted, so an ide-helper tag
    // for the timestamps is how one took the weakening at no site at all.
    'an inherited override, over dates a docblock tag had already typed' => [Hourglass::class, 'Hourglass', true],

    // Quiet, a row per reason the loss did not happen.
    'casts naming their own format, which never reach the hook' => [Metronome::class, 'Metronome', false],
    'an inherited override on a model with no date attribute at all' => [Signpost::class, 'Signpost', false],
    // The last pass that can change a key: a mutated attribute is serialised as the accessor returned
    // it, never through the hook.
    'an accessor publishing the model\'s only date instead' => [Sandglass::class, 'Sandglass', false],
    'date columns the visibility gate keeps out of every response' => [Daybook::class, 'Daybook', false],
    'no override, so the framework writes its own form' => [Waterclock::class, 'Waterclock', false],
    'no override, over a date cast' => [Astrolabe::class, 'Astrolabe', false],
]);

/**
 * What each of those date attributes publishes, column by column — the shape half of the table above. A
 * `@property` tag decides that the column EXISTS and never what shape it has: the response carries what
 * `serializeDate()` wrote, so a consumer handed the Carbon class a tag names gets an object they can
 * never receive.
 */
it('publishes a date attribute at what the hook really writes for it', function (string $fqcn, string $component, string $column, array $expected): void {
    expect(modelSchema(new ClassT($fqcn))[$component]['properties'][$column])->toBe($expected);
})->with([
    // Weakened: the override sends a bespoke string no keyword names.
    'a datetime cast under an override' => [Chronicle::class, 'Chronicle', 'published_at', ['type' => 'string']],
    'a framework timestamp under an override' => [Chronicle::class, 'Chronicle', 'created_at', ['type' => 'string']],
    'the other framework timestamp' => [Chronicle::class, 'Chronicle', 'updated_at', ['type' => 'string']],
    'a docblock-typed timestamp under an inherited override' => [Hourglass::class, 'Hourglass', 'created_at', ['type' => 'string']],
    'the other docblock-typed timestamp' => [Hourglass::class, 'Hourglass', 'updated_at', ['type' => 'string']],
    'a docblock-typed $dates column under an inherited override' => [Hourglass::class, 'Hourglass', 'posted_at', ['type' => 'string']],

    // Kept: a cast naming its own format is written with that parameter and never reaches the hook.
    'a cast naming an ISO format' => [Metronome::class, 'Metronome', 'beat_on', ['type' => 'string', 'format' => 'date']],
    'a cast naming a pattern no keyword describes' => [
        Metronome::class, 'Metronome', 'chimed_on',
        ['type' => 'string', 'description' => 'Serialized using the date format "d/m/Y".'],
    ],

    // Kept at the framework's own form, which is where the `date-time` claim comes from.
    'a date column an accessor publishes' => [Sandglass::class, 'Sandglass', 'posted_at', ['type' => 'string', 'format' => 'date-time']],
    'a docblock-typed $dates column with no override' => [Waterclock::class, 'Waterclock', 'posted_at', ['type' => 'string', 'format' => 'date-time']],
    // A tag naming the DB column's type rather than a class is not wrong about the wire, only short of it.
    'a $dates column a tag typed as a plain string' => [Waterclock::class, 'Waterclock', 'sealed_at', ['type' => 'string', 'format' => 'date-time']],
    // A `date` cast rounds to start-of-day and then writes through the hook like any other date, so the
    // body carries a full date-time; `format: date` would be a claim the server's own bytes fail.
    'a docblock-typed date cast' => [Astrolabe::class, 'Astrolabe', 'sighted_on', ['type' => 'string', 'format' => 'date-time']],
    'a date cast no docblock tagged' => [Astrolabe::class, 'Astrolabe', 'filed_on', ['type' => 'string', 'format' => 'date-time']],
]);

/** The notice's own sentence, which the tables above only count. */
it('names the weakened columns, and a remedy that exists', function (): void {
    $registry = modelRegistry(new ClassT(Chronicle::class));

    // The condition is a method the model declares, so no annotation clears it and none can put the
    // `format` back either — the help says so rather than sending the reader after an annotation that
    // does not exist.
    $note = array_values(array_filter(
        $registry->diagnostics(),
        static fn ($d): bool => $d->code === 'eloquent.custom-date-serialization',
    ));
    expect($note[0]->help)->toBe(
        'Those columns are recovered as `type: string` without a `format`, and no annotation puts one back: no attribute carries a column format, and a docblock type has no format to state. If clients need an exact one, state it in an overlay, which corrects the document and leaves this notice naming the model. A column whose cast names its own format (`datetime:d/m/Y`) is not among them.',
    )
        // Both halves speak for the recovery rather than the finished node — an overlay answers this
        // one, which is the remedy the help itself names
        // (docs/design/defect-classes.md §"A diagnostic that asserts an outcome it never reads").
        ->and($note[0]->message)->toContain('the shapes recovered for them are plain strings with no format.')
        ->and($note[0]->message)->not->toContain('documented')
        // The attributes are NAMED. A model can carry both kinds at once, so a sentence claiming "its
        // date attributes" would be false for a column whose cast writes its own format — which is
        // what `Metronome` in this suite is. Named, in a pinned order, because the names are published.
        ->and($note[0]->message)->toContain('(created_at, published_at, updated_at)');
});

it('reflects $with and the serializeDate override in the model facts', function (): void {
    $boutique = (new EloquentModelReflector)->facts(Boutique::class);
    expect($boutique['with'])->toBe(['posts', 'owner'])
        ->and($boutique['overridesSerializeDate'])->toBeFalse();

    $chronicle = (new EloquentModelReflector)->facts(Chronicle::class);
    expect($chronicle['overridesSerializeDate'])->toBeTrue()
        ->and($chronicle['with'])->toBe([]);
});

it('names a magic column with the class-level #[Mock] form, and says so when the column is not there', function (): void {
    // A column is not a PHP property, so nothing on a model can carry a property-level attribute — this
    // form is the whole of `#[Mock]` for Eloquent, framework-synthesised timestamps included.
    $registry = modelRegistry(new ClassT(Persona::class));
    $persona = $registry->schemas()['Persona'];

    expect($persona['properties']['email']['x-docuccino'])->toBe(['mock' => ['faker' => 'safeEmail']])
        ->and($persona['properties']['created_at']['x-docuccino'])->toBe(['mock' => ['faker' => 'dateTimeThisYear']])
        ->and($persona['properties']['id'])->not->toHaveKey('x-docuccino')
        ->and(array_map(static fn ($d): string => $d->code, $registry->diagnostics()))
        ->toBe(['attribute.mock-unknown-property']);
});
