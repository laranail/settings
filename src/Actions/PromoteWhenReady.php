<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Drivers\JsonDriver;
use Simtabi\Laranail\Settings\Data\PromotionReport;
use Simtabi\Laranail\Settings\Data\PromotionRequest;
use Simtabi\Laranail\Settings\Data\PromotionSelection;
use Simtabi\Laranail\Settings\Exceptions\PromotionLocked;

/**
 * Promote a store's runtime file if it is ready to be promoted: the file exists, the database
 * connection answers and the store's table exists. Returns null when it is not, or when another
 * process holds the promotion lock.
 *
 * The checks are ordered by cost: one file existence check; for a selective promotion, one read
 * of the file to see whether it still holds a selected value; then the schema readiness that
 * db-tools caches. A store with no file, or with only values the selection leaves out, runs no query.
 */
final readonly class PromoteWhenReady
{
    public function __construct(private PromoteStore $promote) {}

    /** @param ?PromotionSelection $only what to copy; null for the store's `promote` selection */
    public function handle(Store $store, ?Actor $actor = null, ?PromotionSelection $only = null): ?PromotionReport
    {
        $only ??= $store->promotion;

        if (! $this->holdsSelected($store, $only) || $store->database()?->isReady($store->name) !== true) {
            return null;
        }

        try {
            return $this->promote->handle($store, new PromotionRequest(only: $only), $actor);
        } catch (PromotionLocked) {
            return null;
        }
    }

    /** Whether the store has a database layer and its runtime file still holds a selected value. */
    private function holdsSelected(Store $store, PromotionSelection $only): bool
    {
        $file = $store->runtimeFile();

        if (! $file instanceof JsonDriver || ! $store->hasDatabaseLayer() || ! $file->hasFile($store->name)) {
            return false;
        }

        return $only->isEverything() || array_any($file->all($store->name), $only->includes(...));
    }
}
