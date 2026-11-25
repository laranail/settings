<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Simtabi\Laranail\Settings\Data\ConfigurationValue;
use Simtabi\Laranail\Settings\Data\ConfiguratorReport;
use Simtabi\Laranail\Settings\Enums\ConfiguratorMode;
use Simtabi\Laranail\Settings\Services\Configuration\PersistedConfiguration;
use Simtabi\Laranail\Settings\Services\Configurator\ConfiguratorRegistry;
use Simtabi\Laranail\Settings\Support\LeftoverEnvironment;

/**
 * Describe how the package is set up: the registrations and what became of each, and every
 * configuration key with its value and source. Values that could hold a secret are redacted.
 */
final readonly class DescribeConfiguration
{
    /** A key whose last segment names one of these holds a value that is not shown. */
    private const string SECRET = '/(password|secret|token|webhook|dsn|credential|api_key)s?$/i';

    public function __construct(
        private ConfiguratorRegistry $registry,
        private Repository $config,
        private Application $app,
        private PersistedConfiguration $persisted,
    ) {}

    public function handle(): ConfiguratorReport
    {
        $applied = $this->registry->applied();
        /** @var array<array-key, mixed> $defaults */
        $defaults = require dirname(__DIR__, 2).'/config/settings.php';
        $flatDefaults = self::flatten($defaults);
        $entries = [];

        foreach (self::flatten($this->config->array('laranail.settings', [])) as $key => $value) {
            $owner = $applied?->owner($key);
            $source = match (true) {
                $owner !== null => 'configurator',
                array_key_exists($key, $flatDefaults) && $flatDefaults[$key] === $value => 'default',
                default => 'config',
            };
            $entries[] = new ConfigurationValue($key, $this->redacted($key, $value), $source, $owner);
        }

        if ($this->persisted->isEnabled()) {
            foreach (['stores' => $this->persisted->stores(), 'layers' => $this->persisted->layers()] as $kind => $named) {
                foreach ($named as $name => $config) {
                    foreach (self::flatten($config, $kind.'.'.$name) as $key => $value) {
                        $entries[] = new ConfigurationValue($key, $this->redacted($key, $value), 'persisted');
                    }
                }
            }
        }

        return new ConfiguratorReport(
            $this->registry->registration(),
            $this->registry->registrations(),
            $applied->contributions ?? [],
            $applied->mode ?? ConfiguratorMode::Loose,
            $this->app instanceof CachesConfiguration && $this->app->configurationIsCached(),
            $entries,
            LeftoverEnvironment::find(),
        );
    }

    /**
     * Dot keys to leaf values; a list is a leaf.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat = [...$flat, ...self::flatten($value, $path)];
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    private function redacted(string $key, mixed $value): mixed
    {
        $last = (string) strrchr('.'.$key, '.');

        return $value !== null && preg_match(self::SECRET, substr($last, 1)) === 1 ? '[redacted]' : $value;
    }
}
