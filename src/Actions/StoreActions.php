<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Actions\Categories\CategoryActions;

/**
 * The store-level actions a settings handle delegates to: categories, promotion, flushing an
 * owner's values and warming or clearing the cache. Held together so a handle is built from one
 * collaborator. The cache actions are optional so a bundle built before Phase 3 still builds; a
 * handle without them refuses the cache calls.
 */
final readonly class StoreActions
{
    public function __construct(
        public CategoryActions $categories,
        public PromoteStore $promote,
        public FlushScope $flush,
        public ?WarmCache $warmCache = null,
        public ?ClearCache $clearCache = null,
    ) {}
}
