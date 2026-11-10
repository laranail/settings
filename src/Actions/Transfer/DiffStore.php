<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Transfer;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\Transfer\StoreDiff;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;
use Simtabi\Laranail\Settings\Services\Transfer\StoreSnapshots;

/** Compare a store with an export file, without exposing any value. */
final readonly class DiffStore
{
    public function __construct(
        private AccessGuard $access,
        private StoreSnapshots $snapshots,
    ) {}

    public function handle(Store $store, ExportDocument $document, ?Actor $actor = null): StoreDiff
    {
        $this->access->authorize($actor, Ability::Export, new Subject($store->name));

        return $this->snapshots->diff($store, $document);
    }
}
