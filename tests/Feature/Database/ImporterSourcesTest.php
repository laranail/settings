<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Schema\Blueprint;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Contracts\DiskImporter;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Services\Transfer\Importers;

/*
 * Importers refuse source keys that normalise to the same settings key, before anything is
 * written; the toolkit importer reads its file from any filesystem disk, defaulting to toolkit's
 * own `laranail.toolkit.settings` disk and path.
 */

beforeEach(function (): void {
    useDatabase();
});

function toolkitFile(array $data): string
{
    $path = sys_get_temp_dir() . '/laranail-toolkit-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode($data));

    return $path;
}

it('refuses toolkit keys that collide after normalising, in a dry run and when applied, writing nothing', function (array $data, string $group): void {
    $path = toolkitFile($data);

    foreach ([false, true] as $apply) {
        expect(Artisan::call('laranail::settings.import', ['source' => $path, '--from' => 'toolkit', '--apply' => $apply]))->toBe(1)
            ->and(Artisan::output())->toContain('collide')->toContain($group);
    }

    expect(fn () => app(Importers::class)->get('toolkit')->read($path, new StoreName('settings')))->toThrow(InvalidImport::class, $group)
        ->and(DB::table('settings')->count())->toBe(0);
})->with([
    'case'                 => [['Mail' => ['Host' => 'a'], 'mail' => ['host' => 'b'], 'site' => 'kept'], '[Mail.Host, mail.host] -> mail.host'],
    'hyphen vs underscore' => [['mail-host' => 'a', 'mail_host' => 'b', 'site' => 'kept'], '[mail-host, mail_host] -> mail_host'],
    'case and hyphen'      => [['Mail-Host' => 'a', 'mail_host' => 'b', 'site' => 'kept'], '[Mail-Host, mail_host] -> mail_host'],
    'space vs underscore'  => [['debug mode' => true, 'debug_mode' => false], '[debug mode, debug_mode] -> debug_mode'],
    'nested vs dotted'     => [['mail' => ['host' => 'a'], 'mail.host' => 'b'], '[mail.host, mail.host] -> mail.host'],
]);

it('names every colliding group in one failure', function (): void {
    $path = toolkitFile(['A' => 1, 'a' => 2, 'b-c' => 3, 'b_c' => 4, 'B_C' => 5, 'ok' => 6]);

    expect(fn () => app(Importers::class)->get('toolkit')->read($path, new StoreName('settings')))
        ->toThrow(InvalidImport::class, 'toolkit: 2 groups of source keys collide after normalising; nothing was imported: [A, a] -> a; [b-c, b_c, B_C] -> b_c.');
});

it('refuses colliding spatie and akaunting keys before writing', function (): void {
    Schema::create('spatie_settings', static function (Blueprint $table): void {
        $table->id();
        $table->string('group');
        $table->string('name');
        $table->boolean('locked')->default(false);
        $table->json('payload');
    });
    DB::table('spatie_settings')->insert([
        ['group' => 'general', 'name' => 'site_name', 'locked' => false, 'payload' => json_encode('a')],
        ['group' => 'General', 'name' => 'Site-Name', 'locked' => false, 'payload' => json_encode('b')],
    ]);
    Schema::create('akaunting_settings', static function (Blueprint $table): void {
        $table->id();
        $table->string('key');
        $table->text('value')->nullable();
    });
    DB::table('akaunting_settings')->insert([['key' => 'invoice-prefix', 'value' => 'a'], ['key' => 'Invoice_Prefix', 'value' => 'b']]);

    expect(Artisan::call('laranail::settings.import', ['source' => ':spatie_settings', '--from' => 'spatie', '--apply' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('[general.site_name, General.Site-Name] -> general.site_name')
        ->and(Artisan::call('laranail::settings.import', ['source' => ':akaunting_settings', '--from' => 'akaunting', '--apply' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('[invoice-prefix, Invoice_Prefix] -> invoice_prefix')
        ->and(DB::table('settings')->count())->toBe(0);
});

it('imports a non-colliding toolkit file exactly as before', function (): void {
    $fixtures = __DIR__ . '/../../Fixtures/Importers/';
    $document = app(Importers::class)->get('toolkit')->read($fixtures . 'toolkit-non-colliding.json', new StoreName('settings'));

    expect($document->toJson())->toBe(file_get_contents($fixtures . 'toolkit-non-colliding.export.json'));
});

it('reads the toolkit file from a filesystem disk', function (): void {
    Storage::fake('s3');
    Storage::disk('s3')->put('backups/settings.json', json_encode(['mail' => ['host' => 'smtp.remote']]));

    $importer = app(Importers::class)->get('toolkit');

    expect($importer)->toBeInstanceOf(DiskImporter::class)
        ->and($importer instanceof DiskImporter ? $importer->readFromDisk('s3', 'backups/settings.json', new StoreName('settings'))->settings[0]->value : null)->toBe('smtp.remote')
        ->and(Artisan::call('laranail::settings.import', ['source' => 'backups/settings.json', '--from' => 'toolkit', '--disk' => 's3', '--apply' => true]))->toBe(0)
        ->and(settings()->get('mail.host'))->toBe('smtp.remote')
        ->and(Artisan::call('laranail::settings.import', ['source' => 'missing.json', '--from' => 'toolkit', '--disk' => 's3']))->toBe(1)
        ->and(Artisan::output())->toContain('toolkit: [missing.json] does not exist on disk [s3].')
        ->and(Artisan::call('laranail::settings.import', ['source' => 'x.json', '--from' => 'toolkit', '--disk' => 'no-such-disk']))->toBe(1)
        ->and(Artisan::output())->toContain('no-such-disk')
        ->and(Artisan::call('laranail::settings.import', ['source' => ':settings', '--from' => 'spatie', '--disk' => 's3']))->toBe(1)
        ->and(Artisan::output())->toContain('does not read from a disk');
});

it('defaults to toolkit\'s own disk and path when no source is given', function (): void {
    Storage::fake('s3');
    Storage::disk('s3')->put('custom/toolkit.json', json_encode(['site' => ['name' => 'From config']]));
    config()->set('laranail.toolkit.settings', ['disk' => 's3', 'path' => 'custom/toolkit.json']);

    expect(Artisan::call('laranail::settings.import', ['--from' => 'toolkit', '--apply' => true]))->toBe(0)
        ->and(settings()->get('site.name'))->toBe('From config');
});

it('falls back to toolkit\'s documented default disk and path when its config is absent', function (): void {
    config()->set('laranail.toolkit');
    Storage::disk('local')->put('laranail/settings.json', json_encode(['site' => ['name' => 'Default']]));

    expect(Artisan::call('laranail::settings.import', ['--from' => 'toolkit', '--apply' => true]))->toBe(0)
        ->and(settings()->get('site.name'))->toBe('Default');
});

it('reads an export file from a disk too', function (): void {
    Storage::fake('s3');
    settings()->set('site.name', 'Acme');
    Storage::disk('s3')->put('exports/settings.json', settings()->export()->toJson());
    settings()->forget('site.name');

    expect(Artisan::call('laranail::settings.import', ['source' => 'exports/settings.json', '--disk' => 's3', '--apply' => true]))->toBe(0)
        ->and(settings()->get('site.name'))->toBe('Acme');
});
