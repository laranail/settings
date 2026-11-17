<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

use ReflectionClass;
use Simtabi\Laranail\Settings\Exceptions\SettingsMisuse;
use Simtabi\Laranail\Settings\Contracts\SettingsConfigurator;

/**
 * Where the application's configurator was registered: its class, the file the class lives in,
 * and the file and line of the `Settings::useConfigurator()` call.
 */
final readonly class ConfiguratorRegistration
{
    /** @param class-string<SettingsConfigurator> $class */
    public function __construct(
        public string $class,
        public string $classFile,
        public string $file,
        public int $line,
    ) {}

    /** Validates the class and records where it was registered from. */
    public static function of(string $class, string $file, int $line): self
    {
        if (! class_exists($class) || ! is_subclass_of($class, SettingsConfigurator::class)) {
            throw new SettingsMisuse(sprintf('Settings::useConfigurator() at %s:%d: [%s] is not a class implementing %s.', $file, $line, $class, SettingsConfigurator::class));
        }

        return new self($class, (string) new ReflectionClass($class)->getFileName(), $file, $line);
    }

    /** `file:line` of the registration call. */
    public function site(): string
    {
        return $this->file . ':' . $this->line;
    }

    public function describe(): string
    {
        return sprintf('%s (registered at %s)', $this->class, $this->site());
    }
}
