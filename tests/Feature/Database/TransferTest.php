<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Schema\Blueprint;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Enums\ExportMode;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Data\Transfer\StoreDiff;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Data\Transfer\ImportReport;
use Simtabi\Laranail\Settings\Services\Transfer\Importers;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;
use Simtabi\Laranail\Settings\Data\Transfer\ExportedCategory;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/*
 * Exports name categories by slug and settings by scope, key and locale; secrets
 * are redacted unless asked for; import is a dry run until applied, goes through the write
 * pipeline, and creates categories first; importers read other packages without needing them.
 */

beforeEach(function (): void {
    useDatabase(['stores.settings.owners' => ['user']]);
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();
});

function seedStore(): string
{
    $secret = TestCredentials::secret();
    settings()->categories()->create(new NewCategory('mail', 'Mail'));
    settings()->categories()->create(new NewCategory('smtp', 'SMTP', parent: 'mail'));
    settings()->set('site.name', 'Acme');
    settings()->set('mail.port', 587);
    settings()->set('mail.password', $secret);
    settings()->for(new OwnerRef('user', 7))->set('theme', 'dark');
    settings()->for(new OwnerRef('user', 7))->lock('theme');

    return $secret;
}

it('exports categories by slug and settings by identity, redacting secrets by default', function (): void {
    $secret = seedStore();

    $document = settings()->export();
    $json = $document->toJson();
    $settings = $document->settingsByIdentity();

    expect($document->store)->toBe('settings')
        ->and(array_map(static fn (ExportedCategory $category): ?string => $category->parent?->value, $document->categories))->toBe([null, 'mail'])
        ->and(array_keys($settings))->toBe(['global|mail.password|', 'global|mail.port|', 'global|site.name|', 'user:7|theme|'])
        ->and($settings['global|mail.port|']->value)->toBe(587)
        ->and($settings['global|mail.password|']->redacted)->toBeTrue()
        ->and($settings['user:7|theme|']->locked)->toBeTrue()
        ->and($json)->not->toContain($secret)
        ->and($json)->not->toContain('"id"')
        ->and(DB::table('settings_audit')->where('action', AuditAction::Export->value)->count())->toBe(1);
});

it('exports secrets as ciphertext or plaintext only when asked, and plaintext needs view_sensitive', function (): void {
    $secret = seedStore();
    allowGates(Ability::Export);

    expect(settings()->export(ExportMode::Ciphertext)->settingsByIdentity()['global|mail.password|']->ciphertext)->not->toBeNull()->not->toContain($secret)
        ->and(settings()->export(ExportMode::Plaintext)->settingsByIdentity()['global|mail.password|']->value)->toBe($secret)
        ->and(fn (): ExportDocument => settings()->as(user())->export(ExportMode::Plaintext))->toThrow(AccessDenied::class, 'view_sensitive');
});

it('round-trips a store into an empty one through a dry run, then an apply', function (): void {
    seedStore();
    $document = ExportDocument::fromJson(settings()->export(ExportMode::Ciphertext)->toJson());

    useDatabase(['stores.settings.owners' => ['user']]);
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();

    $preview = settings()->previewImport($document);

    expect($preview->applied)->toBeFalse()
        ->and($preview->created)->toHaveCount(4)
        ->and($preview->categories)->toBe(['mail', 'smtp'])
        ->and(DB::table('settings')->count())->toBe(0);

    $report = settings()->import($document);

    expect($report->applied)->toBeTrue()
        ->and(settings()->get('mail.port'))->toBe(587)
        ->and(settings()->for(new OwnerRef('user', 7))->get('theme'))->toBe('dark')
        // Untrusted: the lock in the file is not applied.
        ->and((bool) DB::table('settings')->where('scope', 'user:7')->value('is_locked'))->toBeFalse()
        ->and(settings()->categories()->get('smtp')->parent?->value)->toBe('mail')
        ->and(settings()->compare($document)->isEmpty())->toBeFalse()
        ->and(settings()->compare($document)->changed)->toBe(['global|mail.password|', 'user:7|theme|'])
        ->and(settings()->previewImport($document)->unchanged)->toContain('global|site.name|')
        ->and(DB::table('settings_audit')->where('action', AuditAction::Import->value)->count())->toBe(1)
        ->and(DB::table('settings_audit')->where('action', AuditAction::Update->value)->count())->toBe(4);
});

it('skips redacted values and owners the store does not accept, and reports why', function (): void {
    $document = ExportDocument::fromArray([
        'format'   => 'laranail/settings',
        'version'  => 1,
        'store'    => 'settings',
        'settings' => [
            ['scope' => 'global', 'key' => 'mail.password', 'redacted' => true, 'encrypted' => true],
            ['scope' => 'team:3', 'key' => 'theme', 'value' => 'dark'],
            ['scope' => 'global', 'key' => 'site.name', 'value' => 'Acme'],
        ],
    ]);

    $report = settings()->import($document);

    expect($report->created)->toBe(['global|site.name|'])
        ->and(array_keys($report->skipped))->toBe(['global|mail.password|', 'team:3|theme|'])
        ->and($report->skipped['team:3|theme|'])->toContain('does not accept owner alias [team]');
});

it('applies locks and system categories only from a trusted import, through the command', function (): void {
    seedStore();
    $path = sys_get_temp_dir() . '/laranail-settings-export-' . bin2hex(random_bytes(4)) . '.json';

    expect(Artisan::call('laranail::settings.export', ['path' => $path, '--with-secrets' => true]))->toBe(0);

    useDatabase(['stores.settings.owners' => ['user']]);

    expect(Artisan::call('laranail::settings.import', ['source' => $path]))->toBe(0)
        ->and(Artisan::output())->toContain('Dry run')
        ->and(DB::table('settings')->count())->toBe(0)
        ->and(Artisan::call('laranail::settings.import', ['source' => $path, '--apply' => true, '--trusted' => true]))->toBe(0)
        ->and((bool) DB::table('settings')->where('scope', 'user:7')->value('is_locked'))->toBeTrue()
        ->and(Artisan::call('laranail::settings.diff', ['path' => $path]))->toBe(1)
        ->and(Artisan::output())->toContain('~ global|mail.password|')->not->toContain('user:7|theme|');
});

it('asks for the store name before writing decrypted secrets', function (): void {
    seedStore();
    $path = sys_get_temp_dir() . '/laranail-settings-export-' . bin2hex(random_bytes(4)) . '.json';

    $this->artisan('laranail::settings.export', ['path' => $path, '--decrypt' => true])
        ->expectsQuestion('This writes decrypted secrets. Type the store name [settings] to continue', 'nope')
        ->assertExitCode(1);

    expect(is_file($path))->toBeFalse()
        ->and(Artisan::call('laranail::settings.export', ['path' => $path, '--decrypt' => true, '--confirm' => 'settings']))->toBe(0)
        ->and(Artisan::call('laranail::settings.export'))->toBe(0)
        ->and(Artisan::output())->toContain('"format": "laranail/settings"');
});

it('refuses a file that is not an export', function (): void {
    expect(fn (): ExportDocument => ExportDocument::fromJson('[1]'))->toThrow(InvalidImport::class, 'format: missing')
        ->and(fn (): ExportDocument => ExportDocument::fromJson('{"format":"other"}'))->toThrow(InvalidImport::class, 'not a laranail/settings export')
        ->and(fn (): ExportDocument => ExportDocument::fromJson('{"format":"laranail/settings","version":9}'))->toThrow(InvalidImport::class, 'version')
        ->and(fn (): ExportDocument => ExportDocument::fromArray(['format' => 'laranail/settings', 'version' => 1, 'settings' => [['scope' => 'global', 'key' => 'Bad Key!']]]))->toThrow(InvalidImport::class, 'settings.0')
        ->and(Artisan::call('laranail::settings.import', ['source' => '/nonexistent.json']))->toBe(1);
});

it('imports spatie/laravel-settings rows, keeping their locks for a trusted import', function (): void {
    Schema::create('spatie_settings', static function (Blueprint $table): void {
        $table->id();
        $table->string('group');
        $table->string('name');
        $table->boolean('locked')->default(false);
        $table->json('payload');
    });
    DB::table('spatie_settings')->insert([
        ['group' => 'general', 'name' => 'site_name', 'locked' => false, 'payload' => json_encode('Acme')],
        ['group' => 'general', 'name' => 'Site-Active', 'locked' => true, 'payload' => json_encode(true)],
    ]);

    expect(Artisan::call('laranail::settings.import', ['source' => ':spatie_settings', '--from' => 'spatie', '--apply' => true, '--trusted' => true]))->toBe(0)
        ->and(settings()->get('general.site_name'))->toBe('Acme')
        ->and(settings()->get('general.site_active'))->toBeTrue()
        ->and((bool) DB::table('settings')->where('key', 'general.site_active')->value('is_locked'))->toBeTrue()
        ->and(Artisan::call('laranail::settings.import', ['source' => ':missing_table', '--from' => 'spatie']))->toBe(1);
});

it('imports toolkit\'s JSON store, flattening nested keys and keeping lists as values', function (): void {
    $path = sys_get_temp_dir() . '/laranail-toolkit-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode(['mail' => ['host' => 'smtp.test', 'cc' => ['a@x.test', 'b@x.test']], 'debug' => false]));

    $document = app(Importers::class)->get('toolkit')->read($path, new StoreName('settings'));
    settings()->import($document);

    expect($document->source)->toBe('toolkit')
        ->and(settings()->get('mail.host'))->toBe('smtp.test')
        ->and(settings()->get('mail.cc'))->toBe(['a@x.test', 'b@x.test'])
        ->and(settings()->get('debug'))->toBeFalse()
        ->and(fn () => app(Importers::class)->get('toolkit')->read('/nonexistent', new StoreName('settings')))->toThrow(InvalidImport::class, 'not a file');
});

it('imports akaunting/laravel-setting rows and skips rows scoped by the host', function (): void {
    Schema::create('akaunting_settings', static function (Blueprint $table): void {
        $table->id();
        $table->string('key');
        $table->text('value')->nullable();
        $table->unsignedBigInteger('company_id')->nullable();
    });
    DB::table('akaunting_settings')->insert([
        ['key' => 'invoice.prefix', 'value' => 'INV-', 'company_id' => null],
        ['key' => 'invoice.prefix', 'value' => 'ACME-', 'company_id' => 3],
    ]);

    settings()->import(app(Importers::class)->get('akaunting')->read(':akaunting_settings', new StoreName('settings')));

    expect(settings()->get('invoice.prefix'))->toBe('INV-')
        ->and(fn () => app(Importers::class)->get('nope'))->toThrow(InvalidConfiguration::class, 'laranail.settings.importers.nope');
});

it('needs import to import and export to compare', function (): void {
    $document = new ExportDocument('settings');

    expect(fn (): ImportReport => settings()->as(user())->import($document))->toThrow(AccessDenied::class, 'import')
        ->and(fn (): StoreDiff => settings()->as(user())->compare($document))->toThrow(AccessDenied::class, 'export')
        ->and(settings()->as(Actor::system())->compare($document)->isEmpty())->toBeTrue();
});
