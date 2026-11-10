<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Settings\Actions\SetSetting;
use Simtabi\Laranail\Settings\Actions\LockSetting;
use Simtabi\Laranail\Settings\Typed\TypedSettings;
use Simtabi\Laranail\Settings\Models\SettingsModel;
use Simtabi\Laranail\Settings\Actions\ForgetSetting;
use Simtabi\Laranail\Settings\Actions\UnlockSetting;
use Simtabi\Laranail\Settings\Typed\SettingsMigration;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;

/**
 * Structural rules a reviewer would otherwise have to remember.
 */
arch('every source file declares strict types')
    ->expect('Simtabi\Laranail\Settings')
    ->toUseStrictTypes();

arch('classes are final unless they are an exception base')
    ->expect('Simtabi\Laranail\Settings')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        // Bases callers catch; their subtypes are final.
        SettingsException::class,
        InvalidIdentifier::class,
        // Bases applications extend: a typed settings class, a settings migration.
        TypedSettings::class,
        SettingsMigration::class,
        // The models' shared base.
        SettingsModel::class,
    ]);

arch('nothing outside Providers and Facades reaches into the container')
    ->expect('Simtabi\Laranail\Settings')
    ->not->toUse(['app', 'resolve', 'config', 'env', 'request', 'cache', 'logger'])
    ->ignoring([
        'Simtabi\Laranail\Settings\Providers',
        'Simtabi\Laranail\Settings\Facades',
    ]);

arch('no debugging calls ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

/*
 * Headless: no Blade components, Livewire, Filament or published assets. The one view is the alert
 * mail's markdown body, which the notification names by string and the mailer renders.
 */
arch('the package is headless: no view, Blade, Livewire or Filament reference')
    ->expect('Simtabi\Laranail\Settings')
    ->not->toUse([
        'Illuminate\View',
        Blade::class,
        View::class,
        'Livewire',
        'Filament',
        'view',
    ]);

it('ships only the alert mail view and no assets, components or composers', function (): void {
    $root = dirname(__DIR__, 2);
    $sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
    $inspected = 0;
    $offending = [];

    foreach ($sources as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $inspected++;

        if (preg_match('/->\s*(hasAssets|hasViewComponents?|hasViewComposer|sharesDataWithAllViews)\s*\(/', (string) file_get_contents($file->getPathname())) === 1) {
            $offending[] = $file->getPathname();
        }
    }

    // Files under a directory, relative, sorted; macOS's .DS_Store is the OS's, not the package's.
    $files = static function (string $directory): array {
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getFilename() !== '.DS_Store') {
                $found[] = substr($file->getPathname(), strlen($directory) + 1);
            }
        }

        sort($found);

        return $found;
    };

    // A scan that inspects nothing has broken, not found a clean tree.
    expect($inspected)->toBeGreaterThanOrEqual(354)
        ->and($offending)->toBe([])
        ->and($files($root . '/resources/views'))->toBe(['mail/alert.blade.php'])
        ->and($files($root . '/resources/assets'))->toBe(['.gitkeep']);
});

/*
 * Every write capability is one final action with a single public entry point, shared by every channel.
 */
arch('actions are final and expose handle()')
    ->expect('Simtabi\Laranail\Settings\Actions')
    ->classes()
    ->toBeFinal();

arch('each write action has a handle() method')
    ->expect([
        SetSetting::class,
        ForgetSetting::class,
        LockSetting::class,
        UnlockSetting::class,
    ])
    ->toHaveMethod('handle');
