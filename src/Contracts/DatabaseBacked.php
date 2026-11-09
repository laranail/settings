<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;

/**
 * A layer driver backed by the database: the database driver itself, or the bootstrap gate in
 * front of it. Lets a store find its database layer (for transactions, the audit table and the
 * maintenance commands) without knowing which wrapper is in place.
 */
interface DatabaseBacked extends Driver
{
    /** The database driver behind this layer. */
    public function database(): DatabaseDriver;

    /** False while bootstrap mode skips the layer; the store then behaves as if it had none. */
    public function isActive(): bool;
}
