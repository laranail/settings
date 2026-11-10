<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Exceptions\UnknownOwner;

/**
 * Turns an owner a URL names by morph alias and id into the owner a settings handle takes
 * (§16.10). An unknown alias, or an owner that does not exist, is UnknownOwner (a 404), never a
 * class name taken from the request.
 */
interface OwnerResolver
{
    /** @throws UnknownOwner */
    public function resolve(string $alias, string $id): Settable|OwnerRef;
}
