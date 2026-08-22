<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Eloquent\BelongsToReader;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Codex;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\ContestedRelationModel;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\CustomCaster;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\FilterCastModel;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\FilterRelationModel;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\VetoedRelationModel;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Waybill;

/**
 * The static `belongsTo` reader: literal arguments (positional, named, string class names, an explicit
 * `null`, a relation-name override) become readable relations with a CONCRETE foreign key, and anything
 * read only in part — a non-literal argument, an unpack, a conditional relation, a non-model target —
 * becomes a refusal carrying whatever it still discloses. A `morphTo`, a first-class callable, and
 * non-relation helpers yield nothing at all.
 */
it('reads every literal belongsTo relation off the fixture model, computing the default foreign keys', function (): void {
    ['readable' => $readable, 'refused' => $refused] = (new BelongsToReader)->relations(FilterRelationModel::class);

    // The full readable set, keyed by the CONCRETE foreign key the reader computed — declaration
    // order, both `shared_id` contestants included. A shrunk set means the reader stopped seeing
    // shapes it must read; a grown one means a non-relation candidate leaked through.
    expect(array_column($readable, 'foreignKey'))->toBe([
        'vault_id', 'vault_keeper_id', 'waybill_id', 'custom_owner_id', 'named_keeper_id',
        'sibling_id', 'reference_key', 'opaque_key', 'codex_guid', 'sealed_key',
        'archive_id', 'ancient_id', 'flagged_key', 'shared_id', 'shared_id',
    ]);

    // This model deliberately has NO partially-readable relations.
    expect($refused)->toBe([]);

    $byKey = array_column($readable, null, 'foreignKey');
    expect($byKey['vault_id'])->toBe(['related' => Vault::class, 'foreignKey' => 'vault_id', 'ownerKey' => null])
        ->and($byKey['named_keeper_id'])->toBe(['related' => Waybill::class, 'foreignKey' => 'named_keeper_id', 'ownerKey' => null])
        ->and($byKey['reference_key'])->toBe(['related' => FilterCastModel::class, 'foreignKey' => 'reference_key', 'ownerKey' => 'quantity'])
        ->and($byKey['archive_id'])->toBe(['related' => Vault::class, 'foreignKey' => 'archive_id', 'ownerKey' => null])
        ->and($byKey['ancient_id'])->toBe(['related' => Vault::class, 'foreignKey' => 'ancient_id', 'ownerKey' => null])
        ->and($byKey['codex_guid'])->toBe(['related' => Codex::class, 'foreignKey' => 'codex_guid', 'ownerKey' => null]);
});

it('surfaces a wildcard refusal for a foreign key it cannot read', function (): void {
    ['readable' => $readable, 'refused' => $refused] = (new BelongsToReader)->relations(VetoedRelationModel::class);

    expect(array_column($readable, 'foreignKey'))->toBe(['vault_id'])
        // A constant foreign key still discloses its related class; an unpack discloses nothing.
        ->and($refused)->toBe([
            ['related' => Vault::class, 'foreignKey' => null],
            ['related' => null, 'foreignKey' => null],
        ]);
});

it('surfaces literal-key refusals for conditional relations and non-model targets', function (): void {
    ['readable' => $readable, 'refused' => $refused] = (new BelongsToReader)->relations(ContestedRelationModel::class);

    expect(array_column($readable, 'foreignKey'))->toBe(['contested_id', 'vault_id'])
        // The conditional's two arms each name their literal key; the non-model target keeps both
        // its key and its class, so the file it lives in can still key the cache.
        ->and($refused)->toBe([
            ['related' => Vault::class, 'foreignKey' => 'contested_id'],
            ['related' => FilterCastModel::class, 'foreignKey' => 'branch_id'],
            ['related' => CustomCaster::class, 'foreignKey' => 'relic_id'],
        ]);
});

it('returns nothing for a model with no belongsTo relations', function (): void {
    expect((new BelongsToReader)->relations(Vault::class))->toBe(['readable' => [], 'refused' => []]);
});

it('returns nothing for a class that is not loadable', function (): void {
    expect((new BelongsToReader)->relations('Not\\A\\Model'))->toBe(['readable' => [], 'refused' => []]);
});

it('returns nothing for a model whose declaration has no file to parse', function (): void {
    eval('namespace BelongsToReaderTestEval; final class FileslessModel extends \Illuminate\Database\Eloquent\Model { public function vault(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(\Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault::class); } }');

    expect((new BelongsToReader)->relations('BelongsToReaderTestEval\\FileslessModel'))->toBe(['readable' => [], 'refused' => []]);
});
