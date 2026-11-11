<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Closure;
use Throwable;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Enums\Channel;
use Simtabi\Laranail\Settings\Logging\LogContext;
use Simtabi\Laranail\Settings\Logging\OperationalLogger;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;

/**
 * Promotes every store set to `promote: auto` whose runtime file is waiting and whose database is
 * ready. Run after migrations, after each request and after each queued job, as the system.
 *
 * It never throws: a failure is logged and the file stays, so the file layer keeps answering
 * reads and the next run tries again.
 */
final readonly class PromoteReadyStores
{
    /** @param Closure(): PromoteWhenReady $promote built only when a store is set to `auto` */
    public function __construct(
        private StoreRegistry $stores,
        private OperationalLogger $logger,
        private Closure $promote,
    ) {}

    public function handle(Channel $channel): void
    {
        $actor = Actor::system('laranail::settings.auto-promote', $channel);

        try {
            $stores = $this->stores->all();
        } catch (Throwable $exception) {
            $this->failed(null, $actor, $exception);

            return;
        }

        foreach ($stores as $store) {
            if (! $store->autoPromote) {
                continue;
            }

            try {
                $report = ($this->promote)()->handle($store, $actor);
            } catch (Throwable $exception) {
                $this->failed($store->name->value, $actor, $exception);

                continue;
            }

            if ($report !== null) {
                $this->logger->info(sprintf('laranail/settings promoted store [%s] automatically.', $report->store), LogContext::of(
                    store: $report->store,
                    actor: $actor,
                    extra: ['promoted' => count($report->promoted), 'skipped' => count($report->skipped), 'archive' => $report->archive],
                ));
            }
        }
    }

    private function failed(?string $store, Actor $actor, Throwable $exception): void
    {
        try {
            $this->logger->warning(sprintf('laranail/settings could not promote store [%s] automatically; it will try again.', $store ?? '*'), LogContext::of(
                store: $store,
                actor: $actor,
                extra: ['exception' => $exception::class, 'message' => $exception->getMessage()],
            ));
        } catch (Throwable) {
            // A broken log channel must not reach the request either.
        }
    }
}
