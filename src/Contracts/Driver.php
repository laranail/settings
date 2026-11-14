<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;

/** A storage layer. Reads load every requested scope in one call. */
interface Driver
{
    /**
     * @param list<Scope> $scopes
     *
     * @throws DriverFailure
     */
    public function load(StoreName $store, array $scopes): RecordSet;

    /** @throws DriverFailure */
    public function put(StoreName $store, Record $record): void;

    /** @throws DriverFailure */
    public function forget(StoreName $store, Scope $scope, Key $key, Locale $locale): void;

    public function isWritable(): bool;
}
