<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\OwnerRef;

/** Anything that can own settings. */
interface Settable
{
    /** This owner, by morph alias and id. */
    public function settingsOwner(): OwnerRef;

    /**
     * Owners this one inherits from, nearest first.
     *
     * @return list<OwnerRef>
     */
    public function settingsParents(): array;
}
