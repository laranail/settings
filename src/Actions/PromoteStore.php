<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Closure;
use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Record;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Illuminate\Contracts\Cache\LockProvider;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Illuminate\Contracts\Cache\Factory as Caches;
use Simtabi\Laranail\Settings\Drivers\JsonDriver;
use Simtabi\Laranail\Settings\Data\PromotionReport;
use Simtabi\Laranail\Settings\Events\StorePromoted;
use Simtabi\Laranail\Settings\Data\PromotionRequest;
use Simtabi\Laranail\Settings\Events\StorePromoting;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Exceptions\PromotionLocked;
use Simtabi\Laranail\Settings\Support\AuditedTransaction;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;

/**
 * Promote a store's runtime file into its database layer (design §7.4): needs `promote`.
 *
 * Only what was written at runtime is copied; the shipped layer is never read, so a later
 * release can still change a shipped default (G-28). Each record goes through the value
 * pipeline again (validated, re-encrypted under the current key). A key that already has a
 * database row is skipped unless forced, as is a key a strict store no longer defines. The
 * records and the audit entry commit together; the runtime file is then archived as
 * `<file>.promoted-<timestamp>.json`, so a second run finds nothing and is a no-op.
 *
 * A cache lock (the cache store's atomic lock) keeps two promotions of one store apart. On a
 * node-local cache store that protects one node only; multi-node installs need a shared one.
 */
final readonly class PromoteStore
{
    /** @param Closure(Store, Key): ?Definition $definitions the definition a store has for a key */
    public function __construct(
        private ValuePipeline $pipeline,
        private AccessGuard $access,
        private Dispatcher $events,
        private Caches $caches,
        private Closure $definitions,
    ) {}

    /**
     * @throws PromotionLocked
     * @throws StoreNotWritable when the store has no active database layer
     */
    public function handle(Store $store, PromotionRequest $request = new PromotionRequest, ?Actor $actor = null): PromotionReport
    {
        $subject = new Subject($store->name);

        $this->access->authorize($actor, Ability::Promote, $subject);

        $database = $store->requireDatabase(' to promote into.');
        $runtime = $this->runtime($store);

        if (! $runtime instanceof JsonDriver) {
            return new PromotionReport($store->name->value);
        }

        $lockStore = $this->caches->store()->getStore();
        $lock = $lockStore instanceof LockProvider ? $lockStore->lock('laranail-settings:promote:' . $store->name->value, 600) : null;

        if ($lock !== null && ! $lock->get()) {
            throw new PromotionLocked(sprintf('Store [%s] is already being promoted.', $store->name));
        }

        try {
            return $this->promote($store, $request, $actor, $subject, $database, $runtime);
        } finally {
            $lock?->release();
        }
    }

    private function promote(Store $store, PromotionRequest $request, ?Actor $actor, Subject $subject, DatabaseDriver $database, JsonDriver $runtime): PromotionReport
    {
        $records = $request->from === null ? $runtime->all($store->name) : $runtime->recordsIn($request->from);

        $this->events->dispatch(new StorePromoting($store->name->value));

        [$promoted, $skipped] = new AuditedTransaction($this->access)->run(
            $store,
            AuditAction::Promote,
            $subject,
            $actor,
            fn (): array => $this->copy($store, $request, $database, $records),
            static fn (array $result): array => ['meta' => [
                'promoted' => count($result[0]),
                'skipped'  => count($result[1]),
                'replayed' => $request->from,
            ]],
        );

        // Promoted rows were written past the cache: reset the store's bags.
        $store->forgetCachedStore();

        $archive = $request->from === null ? $runtime->archive($store->name, CarbonImmutable::now()) : null;

        $this->events->dispatch(new StorePromoted($store->name->value, count($promoted), count($skipped), $archive));

        return new PromotionReport($store->name->value, $promoted, $skipped, $archive);
    }

    /**
     * @param list<Record> $records
     *
     * @return array{list<string>, array<string, string>}
     */
    private function copy(Store $store, PromotionRequest $request, DatabaseDriver $database, array $records): array
    {
        $existing = $database->load($store->name, array_map(static fn (Record $record): Scope => $record->scope, $records));
        $promoted = [];
        $skipped = [];

        foreach ($records as $record) {
            $definition = ($this->definitions)($store, $record->key);

            if (! $definition instanceof Definition && $store->strict) {
                $skipped[$record->identity()] = 'undefined';

                continue;
            }

            $current = $existing->find($record->scope, $record->key, $record->locale);

            if ($current instanceof Record && ! $request->force) {
                $skipped[$record->identity()] = 'exists';

                continue;
            }

            $database->put($store->name, new Record(
                scope: $record->scope,
                key: $record->key,
                locale: $record->locale,
                payload: $record->hasValue() ? $this->pipeline->toPayload($record->key, $this->pipeline->fromRecord($record, $definition), $definition) : null,
                type: $definition->type ?? $record->type,
                encrypted: $definition instanceof Definition && $definition->encrypted,
                locked: $record->locked,
                version: ($current->version ?? 0) + 1,
                writtenAt: $record->writtenAt,
                group: $definition?->group(),
                category: $definition?->category,
                public: $definition instanceof Definition && $definition->public,
            ));

            $promoted[] = $record->identity();
        }

        return [$promoted, $skipped];
    }

    /** The first writable JSON layer: where values written before the database existed live. */
    private function runtime(Store $store): ?JsonDriver
    {
        foreach ($store->layers as $layer) {
            if ($layer->driver instanceof JsonDriver && $layer->driver->isWritable()) {
                return $layer->driver;
            }
        }

        return null;
    }
}
