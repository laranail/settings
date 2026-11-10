<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;

/** A layer driver backed by the database, whatever wrapper is in front of it. */
interface DatabaseBacked extends Driver
{
    /** The database driver behind this layer. */
    public function database(): DatabaseDriver;

    /** False while bootstrap mode skips the layer. */
    public function isActive(): bool;
}
