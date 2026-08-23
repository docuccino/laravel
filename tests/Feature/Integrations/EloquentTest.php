<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\BuiltIn\EnumSchema;
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
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Blank;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Boutique;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Chronicle;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Coupon;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\CustomCaster;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Gadget;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Invoice;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Ledger;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Merchant;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Persona;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Post;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault;
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
        Chronicle::class => new ClassMetadata(Chronicle::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('title', ScalarT::string()),
        ]),
    ], callables: (static function (): array {
        // Accessor / custom-caster / relation return types scripted so the in-process mapper test drives
        // the same shapes the real engine recovers; the recovery half is proven out-of-process in
        // RealEngineIntegrationsTest. Keyed by CallableRef::symbol().
        $loc = new SourceLocation('');
        $returning = static fn (DType $type): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite($type, $loc)]);

        return [
            Boutique::class.'::getFullLabelAttribute' => $returning(ScalarT::string()),
            Boutique::class.'::getOptionsAttribute' => $returning(ScalarT::string()),
            CustomCaster::class.'::get' => $returning(ScalarT::string()),
            Boutique::class.'::posts' => $returning(new ClassT('Illuminate\\Database\\Eloquent\\Relations\\HasMany', [new ClassT(Post::class)])),
            Boutique::class.'::owner' => $returning(new ClassT('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo', [new ClassT(Merchant::class)])),
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

it('applies a $visible allow-list', function (): void {
    $gadget = modelSchema(new ClassT(Gadget::class))['Gadget'];

    expect(array_keys($gadget['properties']))->toBe(['id', 'name'])
        ->and($gadget['properties'])->not->toHaveKey('secret');
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

it('keeps the bare-object behaviour but raises an info diagnostic for an undocumented model', function (): void {
    $registry = modelRegistry(new ClassT(Blank::class));

    expect($registry->schemas()['Blank'])->toBe(['type' => 'object', 'properties' => []]);

    $codes = array_map(static fn ($d): string => $d->code, $registry->diagnostics());
    expect($codes)->toContain('eloquent.no-columns');
});

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

it('weakens date claims to plain strings and diagnoses a serializeDate() override', function (): void {
    $registry = modelRegistry(new ClassT(Chronicle::class));
    $chronicle = $registry->schemas()['Chronicle'];

    // The datetime cast + the framework timestamps drop their `format`: the wire format is now
    // statically unknowable (published_at keeps only `type: string`, timestamps likewise).
    expect($chronicle['properties']['published_at'])->toBe(['type' => 'string'])
        ->and($chronicle['properties']['created_at'])->toBe(['type' => 'string'])
        ->and($chronicle['properties']['updated_at'])->toBe(['type' => 'string']);

    $codes = array_map(static fn ($d): string => $d->code, $registry->diagnostics());
    expect($codes)->toContain('eloquent.custom-date-serialization');
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
