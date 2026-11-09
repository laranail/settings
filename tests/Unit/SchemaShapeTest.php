<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Enums\IdType;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Enums\StoreMode;
use Simtabi\Laranail\Settings\Enums\IdentityMode;
use Simtabi\Laranail\Settings\Database\SchemaShape;
use Simtabi\Laranail\Settings\Database\StoreSchema;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/** @param array<string, mixed> $extra */
function settingsConfig(array $extra = []): array
{
    return array_replace_recursive([
        'default' => 'settings',
        'stores'  => ['settings' => ['layers' => ['database', 'runtime']], 'files' => ['layers' => ['runtime']]],
        'layers'  => ['database' => ['driver' => 'database', 'connection' => 'tenant'], 'runtime' => ['driver' => 'json']],
    ], $extra);
}

it('derives the default shape from default configuration, with database stores only', function (): void {
    $shape = SchemaShape::fromConfig(settingsConfig());

    expect($shape->identity)->toBe(IdentityMode::SlugUid)
        ->and($shape->ids)->toBe(IdType::Bigint)
        ->and(array_keys($shape->stores))->toBe(['settings'])
        ->and($shape->store(new StoreName('settings'))->connection)->toBe('tenant')
        ->and($shape->store(new StoreName('files'))->mode)->toBe(StoreMode::Polymorphic)
        ->and($shape->hasStore(new StoreName('files')))->toBeFalse();
});

it('follows db-tools\' upper-case id type unless ids.type is set', function (): void {
    expect(SchemaShape::fromConfig(settingsConfig(), 'UUID')->ids)->toBe(IdType::Uuid)
        ->and(SchemaShape::fromConfig(settingsConfig(), 'UUID')->ownerKeyType)->toBe(IdType::Uuid)
        ->and(SchemaShape::fromConfig(settingsConfig(['ids' => ['type' => 'ULID']]), 'UUID')->ids)->toBe(IdType::Ulid)
        ->and(SchemaShape::fromConfig(settingsConfig(), 'nonsense')->ids)->toBe(IdType::Bigint);
});

it('round-trips through the schema file format', function (): void {
    $shape = SchemaShape::fromConfig(settingsConfig([
        'identity'     => ['mode' => 'slug_per_row'],
        'translations' => ['mode' => 'json'],
        'categories'   => ['soft_deletes' => true],
        'definitions'  => ['table' => true],
        'tables'       => ['prefix' => 'app_'],
        'stores'       => [
            'settings' => ['soft_deletes' => true, 'owners' => ['team']],
            'users'    => ['layers' => ['database'], 'owners' => ['user'], 'mode' => 'dedicated', 'owner' => ['table' => 'members', 'key' => 'uuid', 'type' => 'uuid']],
        ],
    ]));

    expect(SchemaShape::fromArray($shape->toArray())->toArray())->toBe($shape->toArray())
        ->and($shape->store(new StoreName('users'))->fallback?->value)->toBe('settings')
        ->and($shape->store(new StoreName('users'))->ownerKey)->toBe('uuid')
        ->and($shape->differencesFrom($shape))->toBe([]);
});

it('lists every difference between two shapes, one line each', function (): void {
    $before = SchemaShape::fromConfig(settingsConfig());
    $after = SchemaShape::fromConfig(settingsConfig(['identity' => ['mode' => 'uid_only'], 'stores' => ['settings' => ['soft_deletes' => true]]]));

    expect($after->differencesFrom($before))->toBe([
        'switches.identity: slug_uid → uid_only',
        'stores.settings.soft_deletes: false → true',
    ]);
});

it('keeps an existing store entry when the same store is added again', function (): void {
    $shape = SchemaShape::fromConfig(settingsConfig());
    $other = new StoreSchema(new StoreName('settings'), softDeletes: true);

    expect($shape->withStore($other)->store(new StoreName('settings'))->softDeletes)->toBeFalse()
        ->and($shape->withStore(new StoreSchema(new StoreName('extra')))->hasStore(new StoreName('extra')))->toBeTrue();
});

it('names the config path of every invalid switch', function (array $extra, string $message): void {
    expect(fn (): SchemaShape => SchemaShape::fromConfig(settingsConfig($extra)))->toThrow(InvalidConfiguration::class, $message);
})->with([
    'identity'          => [['identity' => ['mode' => 'nope']], 'identity.mode'],
    'owner key'         => [['ids' => ['owner_key' => 'int']], 'ids.owner_key'],
    'id type not text'  => [['ids' => ['type' => 5]], 'ids.type'],
    'translations'      => [['translations' => ['mode' => 'po']], 'translations.mode'],
    'categories'        => [['categories' => ['soft_deletes' => 'yes']], 'categories.soft_deletes'],
    'definitions table' => [['definitions' => ['table' => 'yes']], 'definitions.table'],
    'soft deletes'      => [['stores' => ['settings' => ['soft_deletes' => 1]]], 'soft_deletes'],
    'table'             => [['stores' => ['settings' => ['table' => 5]]], '.table'],
    'bad store name'    => [['stores' => ['Bad Name' => ['layers' => ['database']]]], 'stores.Bad Name'],
    'section'           => [['identity' => 'slug_uid'], 'laranail.settings.identity'],
    'dedicated chain'   => [['stores' => [
        'a' => ['layers' => ['database'], 'owners' => ['x'], 'mode' => 'dedicated', 'owner' => ['table' => 'xs'], 'fallback' => 'b'],
        'b' => ['layers' => ['database'], 'owners' => ['y'], 'mode' => 'dedicated', 'owner' => ['table' => 'ys']],
    ]], 'is dedicated too'],
    'bad fallback name' => [['stores' => ['a' => ['layers' => ['database'], 'owners' => ['x'], 'mode' => 'dedicated', 'owner' => ['table' => 'xs'], 'fallback' => 'Bad Name']]], '.fallback'],
    'owner key column'  => [['stores' => ['a' => ['layers' => ['database'], 'owners' => ['x'], 'mode' => 'dedicated', 'owner' => ['table' => 'xs', 'key' => 'Bad Key']]]], 'owner.key'],
]);

it('refuses a dedicated store schema built without an owner', function (): void {
    expect(fn (): StoreSchema => new StoreSchema(new StoreName('a'), StoreMode::Dedicated))->toThrow(InvalidConfiguration::class, 'dedicated store needs')
        ->and(fn (): StoreSchema => new StoreSchema(new StoreName('a'), ownerTable: 'Bad Table'))->toThrow(InvalidConfiguration::class, 'owner.table');
});

it('parses id types loosely and knows which identity columns each mode has', function (): void {
    expect(IdType::parse(' BIGINT '))->toBe(IdType::Bigint)
        ->and(IdType::parse('serial'))->toBeNull()
        ->and(IdentityMode::UidOnly->hasSlug())->toBeFalse()
        ->and(IdentityMode::SlugPerRow->hasUid())->toBeFalse()
        ->and(IdentityMode::SlugUid->hasSlug() && IdentityMode::SlugUid->hasUid())->toBeTrue();
});
