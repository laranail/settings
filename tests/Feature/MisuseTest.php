<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Facades\Settings;
use Simtabi\Laranail\Settings\Typed\SettingsMigration;
use Simtabi\Laranail\Settings\Exceptions\SettingsError;
use Simtabi\Laranail\Settings\Exceptions\SettingsMisuse;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;

/**
 * Calling the package wrongly throws SettingsMisuse: still a LogicException, so existing catch
 * blocks keep working, and a SettingsError, so one catch covers every settings failure.
 */
function expectMisuse(Closure $call, string $message): void
{
    try {
        $call();
    } catch (Throwable $thrown) {
        expect($thrown)->toBeInstanceOf(SettingsMisuse::class)
            ->toBeInstanceOf(LogicException::class)
            ->toBeInstanceOf(SettingsError::class)
            ->and($thrown->getMessage())->toContain($message);

        return;
    }

    test()->fail('Expected SettingsMisuse: ' . $message);
}

it('throws SettingsMisuse when a missing or redacted lookup is read', function (): void {
    expectMisuse(static fn (): mixed => Lookup::missing()->value(), 'call isFound() or valueOr() first');
    expectMisuse(static fn (): mixed => Lookup::found('x', 'runtime', Scope::global())->redacted()->value(), 'redacted');
});

it('throws SettingsMisuse when flush() has no owner', function (): void {
    expectMisuse(static fn () => settings()->flush(), 'call for($owner) first');
});

it('throws SettingsMisuse when Settings::fake() has no application', function (): void {
    $app = Facade::getFacadeApplication();
    Facade::setFacadeApplication(null);

    try {
        expectMisuse(static fn (): mixed => Settings::fake(), 'needs a booted application');
    } finally {
        Facade::setFacadeApplication($app);
    }
});

it('throws SettingsMisuse when a settings migration runs without the provider', function (): void {
    $property = new ReflectionProperty(SettingsMigration::class, 'migrator');
    $migrator = $property->getValue();
    $property->setValue(null, null);
    $migration = new class extends SettingsMigration
    {
        public function up(): void
        {
            $this->settings();
        }
    };

    try {
        expectMisuse(static fn () => $migration->up(), 'service provider to be registered');
    } finally {
        $property->setValue(null, $migrator);
    }
});

it('never throws a bare LogicException from the source', function (): void {
    $root = dirname(__DIR__, 2) . '/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $inspected = 0;
    $offending = [];

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Exceptions/')) {
            continue;
        }

        $inspected++;

        // Pest's arch expectations do not see global classes, so read the source.
        if (preg_match('/\bLogicException\b/', (string) file_get_contents($file->getPathname())) === 1) {
            $offending[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    expect($inspected)->toBeGreaterThanOrEqual(300)
        ->and($offending)->toBe([]);
});

it('lets one catch take every settings exception', function (): void {
    $classes = array_map(
        static fn (string $file): string => 'Simtabi\\Laranail\\Settings\\Exceptions\\' . basename($file, '.php'),
        glob(dirname(__DIR__, 2) . '/src/Exceptions/*.php') ?: [],
    );
    $throwables = array_values(array_filter($classes, static fn (string $class): bool => is_a($class, Throwable::class, true)));

    expect(count($throwables))->toBeGreaterThanOrEqual(31)
        ->and(array_values(array_filter($throwables, static fn (string $class): bool => ! is_a($class, SettingsError::class, true))))->toBe([])
        ->and(is_a(SettingsException::class, RuntimeException::class, true))->toBeTrue();
});
