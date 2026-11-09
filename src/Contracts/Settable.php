<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\OwnerRef;

/**
 * A model (or anything) that can own settings.
 */
interface Settable
{
    /** This owner, by morph alias and id. */
    public function settingsOwner(): OwnerRef;

    /**
     * Owners this one may inherit from, nearest first (for example team, then tenant).
     *
     * @return list<OwnerRef>
     */
    public function settingsParents(): array;
}
