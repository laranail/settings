<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

use ReflectionClass;
use Composer\InstalledVersions;
use Simtabi\Laranail\Settings\Enums\ConfiguratorScope;
use Simtabi\Laranail\Settings\Exceptions\SettingsMisuse;
use Simtabi\Laranail\Settings\Contracts\SettingsConfigurator;
use Simtabi\Laranail\Settings\Contracts\ModuleSettingsContributor;
use Simtabi\Laranail\Settings\Contracts\PackageSettingsContributor;

/**
 * One registered piece of the settings setup: its class, the file the class lives in, the file and
 * line of the registration call, its scope (package, module or application) and its identity (the
 * Composer package name, the module name, or `app`).
 */
final readonly class ConfiguratorRegistration
{
    /** @param class-string<SettingsConfigurator|PackageSettingsContributor|ModuleSettingsContributor> $class */
    public function __construct(
        public string $class,
        public string $classFile,
        public string $file,
        public int $line,
        public ConfiguratorScope $scope = ConfiguratorScope::Application,
        public string $identity = 'app',
    ) {}

    /**
     * Validates the class, reads its scope from the contract it implements, and records where it
     * was registered from.
     *
     * @param ConfiguratorScope|null $expected the scope the call accepts (useConfigurator() takes the application's only)
     */
    public static function of(string $class, string $file, int $line, ?ConfiguratorScope $expected = null): self
    {
        $site = sprintf('%s:%d', $file, $line);

        if (! class_exists($class)) {
            throw new SettingsMisuse(sprintf('Settings registration at %s: [%s] is not a class.', $site, $class));
        }

        $scopes = array_keys(array_filter([
            ConfiguratorScope::Application->value => is_subclass_of($class, SettingsConfigurator::class),
            ConfiguratorScope::Package->value     => is_subclass_of($class, PackageSettingsContributor::class),
            ConfiguratorScope::Module->value      => is_subclass_of($class, ModuleSettingsContributor::class),
        ]));

        if (count($scopes) !== 1) {
            throw new SettingsMisuse(sprintf(
                'Settings registration at %s: [%s] must implement exactly one of %s, %s and %s.',
                $site,
                $class,
                SettingsConfigurator::class,
                PackageSettingsContributor::class,
                ModuleSettingsContributor::class,
            ));
        }

        $scope = ConfiguratorScope::from((string) $scopes[0]);

        if ($expected instanceof ConfiguratorScope && $scope !== $expected) {
            throw new SettingsMisuse(sprintf('Settings::useConfigurator() at %s: [%s] is not a class implementing %s; register a package or module contributor with Settings::register().', $site, $class, SettingsConfigurator::class));
        }

        /** @var class-string<SettingsConfigurator|PackageSettingsContributor|ModuleSettingsContributor> $class implements exactly one, checked above */
        $classFile = (string) new ReflectionClass($class)->getFileName();

        return new self($class, $classFile, $file, $line, $scope, match ($scope) {
            ConfiguratorScope::Application => 'app',
            ConfiguratorScope::Package     => self::packageOf($classFile, $class, $site),
            ConfiguratorScope::Module      => is_subclass_of($class, ModuleSettingsContributor::class) ? self::moduleOf($class, $site) : '',
        });
    }

    /** `file:line` of the registration call. */
    public function site(): string
    {
        return $this->file . ':' . $this->line;
    }

    public function describe(): string
    {
        return $this->scope === ConfiguratorScope::Application
            ? sprintf('%s (registered at %s)', $this->class, $this->site())
            : sprintf('%s [%s] %s (registered at %s)', $this->scope->value, $this->identity, $this->class, $this->site());
    }

    /** The Composer package whose install path holds the class file; the deepest one wins. */
    private static function packageOf(string $classFile, string $class, string $site): string
    {
        $file = realpath($classFile) ?: $classFile;
        $best = null;
        $depth = -1;

        foreach (InstalledVersions::getInstalledPackages() as $package) {
            $path = InstalledVersions::getInstallPath($package);
            $path = $path === null ? false : realpath($path);

            if ($path !== false && str_starts_with($file, $path . DIRECTORY_SEPARATOR) && strlen($path) > $depth) {
                $best = $package;
                $depth = strlen($path);
            }
        }

        return $best ?? throw new SettingsMisuse(sprintf('Settings::register() at %s: [%s] is not inside an installed Composer package, so it has no package identity; implement ModuleSettingsContributor or SettingsConfigurator instead.', $site, $class));
    }

    /** @param class-string<ModuleSettingsContributor> $class */
    private static function moduleOf(string $class, string $site): string
    {
        $name = trim($class::moduleName());

        return $name !== '' ? $name : throw new SettingsMisuse(sprintf('Settings::register() at %s: [%s]::moduleName() is empty.', $site, $class));
    }
}
