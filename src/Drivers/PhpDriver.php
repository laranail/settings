<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Drivers;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;

/**
 * A read-only PHP file returning `['dotted.key' => value]`: the shipped-defaults layer.
 * Keys are flat and dotted at the top level, so a value may itself be an array. A missing file
 * is an empty layer. Opcache keeps the file cheap to read on every request.
 */
final class PhpDriver implements Driver
{
    /** @var array<array-key, mixed>|null */
    private ?array $values = null;

    public function __construct(
        private readonly string $path,
        private readonly ReadOnlyValues $reader,
    ) {}

    public function load(StoreName $store, array $scopes): RecordSet
    {
        return $this->reader->toRecordSet($this->values(), $scopes, $this->path);
    }

    public function put(StoreName $store, Record $record): void
    {
        throw new StoreNotWritable(sprintf('The PHP layer [%s] is read-only.', $this->path));
    }

    public function forget(StoreName $store, Scope $scope, Key $key, Locale $locale): void
    {
        throw new StoreNotWritable(sprintf('The PHP layer [%s] is read-only.', $this->path));
    }

    public function isWritable(): bool
    {
        return false;
    }

    /** @return array<array-key, mixed> */
    private function values(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        if (! is_file($this->path)) {
            return $this->values = [];
        }

        $values = require $this->path;

        if (! is_array($values)) {
            throw new DriverFailure(sprintf('Settings file [%s] must return an array.', $this->path));
        }

        return $this->values = $values;
    }
}
