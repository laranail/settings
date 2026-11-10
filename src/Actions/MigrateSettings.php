<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Closure;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Data\SettingsMigrationStep;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;

/**
 * Run one settings migration step (G-17): rename or move a key across every owner and locale,
 * delete it everywhere, or add a global value. Values are read and written through the value
 * pipeline (so a value is re-validated, re-cast and re-encrypted under the destination's
 * definition), with history; locks move with them. A migration is deploy-time code, so it writes
 * past approvals the way trusted code does, needs `manage_definitions` when given an actor, and is
 * audited once per step. On a database store every scope is migrated; on a file-only store, the
 * global value. Returns how many values it touched.
 */
final readonly class MigrateSettings
{
    /** @param Closure(Store, Key): ?Definition $definitions */
    public function __construct(
        private StoreRegistry $stores,
        private SettingWriter $writer,
        private ValuePipeline $pipeline,
        private AccessGuard $access,
        private Closure $definitions,
    ) {}

    public function handle(SettingsMigrationStep $step, ?Actor $actor = null): int
    {
        $from = $this->stores->get($step->fromStore);
        $subject = new Subject($from->name, $step->fromKey);
        $this->access->authorize($actor, Ability::ManageDefinitions, $subject);

        $writer = $this->writer->causedBy($actor ?? Actor::system('settings migration'), 'settings migration: ' . $step->operation . ' ' . $step->fromKey);
        $records = $this->records($from, $step->fromKey);

        $touched = $from->transaction(function () use ($step, $from, $records, $writer): int {
            if ($step->operation === SettingsMigrationStep::ADD) {
                return $records === [] || ! array_any($records, static fn (Record $record): bool => $record->scope->isGlobal())
                    ? $this->add($from, $step, $writer)
                    : 0;
            }

            $to = $step->toStore instanceof StoreName ? $this->stores->get($step->toStore) : null;
            $touched = 0;

            foreach ($records as $record) {
                $owner = $record->scope->owner();

                if ($to instanceof Store && $step->toKey instanceof Key && $record->payload !== null) {
                    $value = $this->pipeline->fromRecord($record, ($this->definitions)($from, $step->fromKey));
                    $writer->set($to, $step->toKey, $record->locale, $owner, ($this->definitions)($to, $step->toKey), $value);

                    if ($record->locked) {
                        $writer->lock($to, $step->toKey, $record->locale, $owner);
                    }
                }

                if ($record->locked) {
                    $writer->unlock($from, $step->fromKey, $record->locale, $owner);
                }

                $writer->forget($from, $step->fromKey, $record->locale, $owner, null);
                $touched++;
            }

            return $touched;
        });

        $this->access->auditor()->record(AuditAction::Migrate, AuditOutcome::Allowed, $subject, $actor, meta: [
            'operation' => $step->operation,
            'to_store'  => $step->toStore?->value,
            'to_key'    => $step->toKey?->value,
            'touched'   => $touched,
        ]);

        return $touched;
    }

    private function add(Store $store, SettingsMigrationStep $step, SettingWriter $writer): int
    {
        $writer->set($store, $step->fromKey, Locale::none(), null, ($this->definitions)($store, $step->fromKey), $step->value);

        return 1;
    }

    /** @return list<Record> every row of the key: every scope on a database store, else the global one */
    private function records(Store $store, Key $key): array
    {
        $database = $store->database();
        $scopes = $database instanceof DatabaseDriver
            ? array_values(array_filter(array_map(Scope::fromString(...), $database->scopes($store->name))))
            : [Scope::global()];
        $layer = $database instanceof DatabaseDriver ? $database : $store->writableLayer()->driver;

        return array_values(array_filter($layer->load($store->name, $scopes)->all(), static fn (Record $record): bool => $record->key->value === $key->value));
    }
}
