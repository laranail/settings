<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Drivers;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Illuminate\Contracts\Config\Repository;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;

/**
 * A read-only view over one Laravel config prefix; nested arrays become dotted keys, lists stay values.
 */
final readonly class ConfigDriver implements Driver
{
    public function __construct(
        private Repository $config,
        private string $prefix,
        private ReadOnlyValues $reader,
    ) {}

    public function load(StoreName $store, array $scopes): RecordSet
    {
        $section = $this->config->get($this->prefix, []);

        return $this->reader->toRecordSet(is_array($section) ? $this->flatten($section) : [], $scopes, 'config:' . $this->prefix);
    }

    public function put(StoreName $store, Record $record): void
    {
        throw new StoreNotWritable(sprintf('The config layer [%s] is read-only.', $this->prefix));
    }

    public function forget(StoreName $store, Scope $scope, Key $key, Locale $locale): void
    {
        throw new StoreNotWritable(sprintf('The config layer [%s] is read-only.', $this->prefix));
    }

    public function isWritable(): bool
    {
        return false;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $name => $value) {
            $key = $prefix . $name;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat += $this->flatten($value, $key . '.');

                continue;
            }

            $flat[$key] = $value;
        }

        return $flat;
    }
}
