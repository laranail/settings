<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Tests\Support\Pairwise;
use Simtabi\Laranail\Settings\Actions\SyncDefinitions;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;
use Simtabi\Laranail\Settings\Database\SchemaShapeRepository;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Tenancy\ClosureTenantDiscriminator;

/*
 * §13: the schema switches, kept tested. Every pair of switch values meets in at least one shape
 * (pairwise), and each shape runs the same scenario: migrate, write global and owner values,
 * categories, a forget followed by a set, locks, the definitions table, then `doctor` against the
 * recorded schema. Runs on SQLite with the rest of the suite and, being in the `database` group,
 * on MySQL and PostgreSQL in CI. LARANAIL_SETTINGS_MATRIX=full runs the whole product instead
 * (the weekly job).
 */

/** @return array<string, list<string|bool>> */
function switchFactors(): array
{
    return [
        'identity'                => ['slug_uid', 'slug_per_row', 'uid_only'],
        'ids'                     => ['bigint', 'uuid', 'ulid'],
        'owner_key'               => ['string', 'typed'],
        'mode'                    => ['polymorphic', 'dedicated'],
        'soft_deletes'            => [false, true],
        'translations'            => ['lang', 'json'],
        'definitions_table'       => [false, true],
        'categories_soft_deletes' => [false, true],
        'tenancy'                 => ['none', 'column'],
    ];
}

/** @return list<array<string, string|bool>> */
function switchShapes(): array
{
    return getenv('LARANAIL_SETTINGS_MATRIX') === 'full' ? Pairwise::product(switchFactors()) : Pairwise::rows(switchFactors());
}

it('covers every pair of switch values with a counted set of shapes', function (): void {
    $shapes = switchShapes();
    $covered = [];

    foreach ($shapes as $shape) {
        foreach (Pairwise::pairs($shape) as $pair) {
            $covered[$pair] = true;
        }
    }

    $all = [];

    foreach (Pairwise::product(switchFactors()) as $row) {
        foreach (Pairwise::pairs($row) as $pair) {
            $all[$pair] = true;
        }
    }

    // 141 pairs among the eight Phase 2b switches, plus tenancy's 2 values × the other 18
    // values = 177; the full product doubles from 576 to 1,152.
    expect(count($shapes))->toBe(getenv('LARANAIL_SETTINGS_MATRIX') === 'full' ? 1152 : 10)
        ->and(count($all))->toBe(177)
        ->and(array_diff_key($all, $covered))->toBe([]);
})->group('matrix');

it('runs every shape end to end and reports no drift', function (array $shape): void {
    $dedicated = $shape['mode'] === 'dedicated';
    $ownerStore = $dedicated ? 'user_settings' : 'settings';

    $config = [
        'identity.mode'                => $shape['identity'],
        'ids.type'                     => $shape['ids'],
        'ids.owner_key'                => $shape['owner_key'],
        'translations.mode'            => $shape['translations'],
        'definitions.table'            => $shape['definitions_table'],
        'categories.soft_deletes'      => $shape['categories_soft_deletes'],
        'tenancy.mode'                 => $shape['tenancy'],
        'stores.settings.owners'       => $dedicated ? [] : ['user'],
        'stores.settings.soft_deletes' => $shape['soft_deletes'],
        'stores.settings.inherits'     => true,
    ];

    if ($dedicated) {
        $config['stores.user_settings'] = [
            'layers'       => ['database'],
            'owners'       => ['user'],
            'mode'         => 'dedicated',
            'owner'        => ['table' => 'users', 'type' => 'bigint'],
            'soft_deletes' => $shape['soft_deletes'],
            'inherits'     => true,
        ];
    }

    useDatabase($config, stores: $dedicated ? ['user_settings'] : [], ownerTables: ['users' => 'bigint']);
    // Column-mode tenancy runs the whole scenario inside tenant `acme`.
    app()->instance(TenantDiscriminator::class, new ClosureTenantDiscriminator(static fn (): ?string => $shape['tenancy'] === 'column' ? 'acme' : null));
    app(SchemaShapeRepository::class)->record();
    DB::table('users')->insert(['id' => 1, 'name' => 'Ada']);

    $user = new OwnerRef('user', 1);

    // Categories: create with translations, delete, create again (restored when trashed).
    $categories = settings()->categories();
    $mail = $categories->create(new NewCategory('mail', 'Mail', translations: ['fr' => 'Courrier']));
    $categories->delete('mail');
    $again = $categories->create(new NewCategory('mail', 'Email'));
    app(DefinitionRegistry::class)->define('mail.host')->inCategory('mail')->labelled('Host')->register();

    // Values: global, owner, category link, forget then set, lock and unlock.
    settings()->set('mail.host', 'smtp.acme.test');
    settings()->set('site.name', 'Acme');
    settings()->for($user)->set('theme', 'dark');
    settings()->for($user)->forget('theme');
    settings()->for($user)->set('theme', 'light');
    settings()->for($user)->lock('theme');
    settings()->for($user)->unlock('theme');

    if ($shape['definitions_table']) {
        expect(app(SyncDefinitions::class)->handle()['synced'])->toBe(1);
    }

    $exit = Artisan::call('laranail::settings.doctor', ['--json' => true]);
    $report = Artisan::output();

    expect(settings()->get('mail.host'))->toBe('smtp.acme.test')
        ->and(settings()->for($user)->get('theme'))->toBe('light')
        ->and(settings()->for($user)->get('site.name'))->toBe('Acme')
        ->and(DB::table($ownerStore)->where('scope', 'user:1')->count())->toBe(1)
        ->and((int) DB::table($ownerStore)->where('scope', 'user:1')->value('version'))->toBe($shape['soft_deletes'] ? 4 : 3)
        ->and(DB::table('settings')->where('key', 'mail.host')->value('category_id'))->toBe($again->id)
        ->and($again->id === $mail->id)->toBe($shape['categories_soft_deletes'])
        ->and(DB::table('settings')->distinct()->pluck($shape['tenancy'] === 'column' ? 'tenant' : 'scope')->contains('acme'))->toBe($shape['tenancy'] === 'column')
        ->and($report)->toContain('No schema drift.')
        ->and($report)->toContain('The configuration matches the recorded schema.')
        ->and($exit)->toBe(0);
})->with(fn (): array => array_map(
    static fn (array $shape): array => [$shape],
    array_combine(array_map(static fn (array $shape): string => implode(',', array_map(static fn (mixed $value): string => is_bool($value) ? ($value ? 'on' : 'off') : $value, $shape)), switchShapes()), switchShapes()),
))->group('matrix');
