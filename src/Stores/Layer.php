<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Stores;

use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Enums\DriverType;
use Simtabi\Laranail\Settings\Enums\Precedence;

/**
 * A named, built layer of a store.
 */
final readonly class Layer
{
    public function __construct(
        public string $name,
        public Driver $driver,
        public ?DriverType $type,
    ) {}

    /** Whether this layer may answer a read for a setting with this precedence. */
    public function answersFor(Precedence $precedence): bool
    {
        return match ($precedence) {
            Precedence::Store  => true,
            Precedence::File   => ! $this->driver->isWritable() && in_array($this->type, [DriverType::Php, DriverType::Json], true),
            Precedence::Config => $this->type === DriverType::Config,
        };
    }
}
