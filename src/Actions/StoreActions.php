<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Actions\Categories\CategoryActions;

/**
 * The store-level actions a settings handle delegates to: categories, promotion and flushing an
 * owner's values. Held together so a handle is built from one collaborator.
 */
final readonly class StoreActions
{
    public function __construct(
        public CategoryActions $categories,
        public PromoteStore $promote,
        public FlushScope $flush,
    ) {}
}
