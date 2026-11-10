<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Actions\MakeStore;
use Simtabi\Laranail\Settings\Database\SchemaShape;
use Simtabi\Laranail\Settings\Database\StoreSchema;
use Simtabi\Laranail\Settings\Database\SchemaShapeRepository;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

// Writes into the testbench application shared by every process (config/, database/), so it
// cannot run beside itself in parallel; the parallel mutation run leaves this group out.
pest()->group('shared-app');

function shapes(): SchemaShapeRepository
{
    return app(SchemaShapeRepository::class);
}

/** The doctor's JSON report. */
function doctorReport(): string
{
    Artisan::call('laranail::settings.doctor', ['--json' => true]);

    return Artisan::output();
}

it('records the configured shape in the schema file and reads it back', function (): void {
    config()->set('laranail.settings.identity.mode', 'uid_only');
    config()->set('laranail.settings.stores.settings.layers', ['database']);
    config()->set('laranail.settings.stores.settings.soft_deletes', true);

    $recorded = shapes()->record();
    app()->forgetScopedInstances();

    expect(File::get(shapes()->path()))->toContain("'identity' => 'uid_only'")
        ->and(shapes()->recorded()?->toArray())->toBe($recorded->toArray())
        ->and(shapes()->current()->store(new StoreName('settings'))->softDeletes)->toBeTrue();
});

it('makes the runtime read the recorded shape, not config changed after migrating (§4.0)', function (): void {
    useDatabase();
    shapes()->record();

    config()->set('laranail.settings.identity.mode', 'uid_only');
    config()->set('laranail.settings.stores.settings.soft_deletes', true);
    app()->forgetScopedInstances();

    settings()->set('site.name', 'Acme');
    settings()->forget('site.name');

    expect(DB::table('settings')->count())->toBe(0)
        ->and(shapes()->current()->identity->value)->toBe('slug_uid');
});

it('keeps recorded switches and stores and only adds new stores on record', function (): void {
    config()->set('laranail.settings.stores.settings.layers', ['database']);
    shapes()->record();

    config()->set('laranail.settings.ids.type', 'uuid');
    config()->set('laranail.settings.stores.settings.soft_deletes', true);
    config()->set('laranail.settings.stores.billing', ['layers' => ['database'], 'soft_deletes' => true]);
    $shape = shapes()->record();

    expect($shape->ids->value)->toBe('bigint')
        ->and($shape->store(new StoreName('settings'))->softDeletes)->toBeFalse()
        ->and($shape->store(new StoreName('billing'))->softDeletes)->toBeTrue();
});

it('records the store make-store generates, from its configuration when it has one', function (): void {
    config()->set('laranail.settings.stores.ledger', ['layers' => ['database'], 'soft_deletes' => true]);

    app(MakeStore::class)->handle(new StoreName('ledger'));
    app(MakeStore::class)->handle(new StoreName('archive'), 'archive_db');

    $recorded = shapes()->recorded();

    expect($recorded?->store(new StoreName('ledger'))->softDeletes)->toBeTrue()
        ->and($recorded?->store(new StoreName('archive'))->connection)->toBe('archive_db');

    File::delete(glob(database_path('migrations') . '/*settings*.php') ?: []);
});

it('refuses a schema file that does not hold a shape', function (string $contents, string $message): void {
    File::put(shapes()->path(), $contents);

    expect(fn (): ?SchemaShape => shapes()->recorded())->toThrow(InvalidConfiguration::class, $message);
})->with([
    'not an array'    => ['<?php return 1;', 'must return an array'],
    'no version'      => ['<?php return [];', 'shape_version'],
    'unknown switch'  => ["<?php return ['shape_version' => 1, 'switches' => ['identity' => 'nope']];", 'switch [identity]'],
    'unknown mode'    => ["<?php return ['shape_version' => 1, 'stores' => ['settings' => ['mode' => 'nope']]];", 'unknown mode'],
    'store not array' => ["<?php return ['shape_version' => 1, 'stores' => ['settings' => 1]];", 'must be an array'],
    'broken PHP'      => ['<?php return [', 'could not be read'],
]);

it('warns when nothing is recorded, and passes once it is', function (): void {
    useDatabase();

    expect(doctorReport())->toContain('No schema file');

    shapes()->record();

    expect(doctorReport())->toContain('The configuration matches the recorded schema.');
});

it('warns about configuration that drifted from the recorded schema', function (): void {
    useDatabase();
    shapes()->record();
    config()->set('laranail.settings.translations.mode', 'json');
    config()->set('laranail.settings.stores.settings.soft_deletes', true);
    app()->forgetScopedInstances();

    $this->artisan('laranail::settings.doctor')
        ->expectsOutputToContain('switches.translations: lang → json')
        ->assertSuccessful();

    expect(doctorReport())->toContain('stores.settings.soft_deletes: false');
});

it('fails on an unrecorded database store, a missing column or a wrong id type', function (): void {
    useDatabase();
    shapes()->write(new SchemaShape(stores: []));

    expect(doctorReport())->toContain('has a database layer but no entry in the schema file');

    $data = shapes()->configured()->toArray();
    $data['shape_version'] = 1;
    File::put(shapes()->path(), '<?php return ' . var_export(['shape_version' => 1] + $data, true) . ';');

    expect(doctorReport())->not->toContain('no entry in the schema file');

    // The recorded shape says soft deletes; the table has no deleted_at.
    shapes()->write(shapes()->configured()->withStore(new StoreSchema(new StoreName('x'))));
    $soft = shapes()->configured()->toArray();
    $soft['stores']['settings']['soft_deletes'] = true;
    File::put(shapes()->path(), '<?php return ' . var_export($soft, true) . ';');
    app()->forgetScopedInstances();

    expect(doctorReport())->toContain('settings.deleted_at');

    // The recorded shape says ulid; the table has a bigint id.
    $ulid = shapes()->configured()->toArray();
    $ulid['switches']['ids'] = 'ulid';
    File::put(shapes()->path(), '<?php return ' . var_export($ulid, true) . ';');
    app()->forgetScopedInstances();

    expect(doctorReport())->toContain('recorded key type is [ulid]');
});

it('warns about columns the recorded shape does not have', function (): void {
    useDatabase(['stores.settings.soft_deletes' => true]);
    $plain = shapes()->configured()->toArray();
    $plain['stores']['settings']['soft_deletes'] = false;
    File::put(shapes()->path(), '<?php return ' . var_export($plain, true) . ';');
    app()->forgetScopedInstances();

    expect(Schema::hasColumn('settings', 'deleted_at'))->toBeTrue()
        ->and(doctorReport())->toContain('Columns the recorded schema does not have');
});
