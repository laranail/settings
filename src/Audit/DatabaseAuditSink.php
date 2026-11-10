<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Audit;

use Closure;
use JsonException;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Contracts\AuditSink;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;

/**
 * Writes each audit entry as a row of `settings_audit` on the connection of the entry's store
 * (or of the default store, for entries without one), so an allowed write and its audit row
 * share one transaction: if the audit insert fails, the write rolls back. The row has no value
 * column; entries never carry one.
 */
final readonly class DatabaseAuditSink implements AuditSink
{
    /** @param Closure(): StoreRegistry $stores resolved per entry, so runtime stores are seen */
    public function __construct(
        private Closure $stores,
    ) {}

    /** Whether there is an active database layer to write this entry to. */
    public function accepts(AuditEntry $entry): bool
    {
        return $this->target($entry) instanceof DatabaseDriver;
    }

    public function record(AuditEntry $entry): void
    {
        $database = $this->target($entry)
            ?? throw new DriverFailure(sprintf('The database audit sink has no active database layer for store [%s].', $entry->store ?? 'default'));

        $store = new StoreName($entry->store ?? 'audit');

        $database->attempt($store, fn (): bool => $database->connection()->table($database->names()->audit())->insert([
            'id'             => $entry->id,
            'occurred_at'    => $entry->occurredAt,
            'action'         => $entry->action->value,
            'outcome'        => $entry->outcome->value,
            'store'          => $entry->store,
            'scope'          => $entry->scope,
            'key'            => $entry->key,
            'locale'         => $entry->locale,
            'version_from'   => $entry->versionFrom,
            'version_to'     => $entry->versionTo,
            'change_request' => null,
            'actor_type'     => $entry->actorType->value,
            'actor_alias'    => $entry->actorAlias,
            'actor_id'       => $entry->actorId,
            'channel'        => $entry->channel->value,
            'ip'             => $entry->ip,
            'user_agent'     => $entry->userAgent,
            'request_id'     => $entry->requestId,
            'reason'         => $entry->reason,
            'meta'           => $this->meta($entry),
        ] + $database->tenantColumns()));
    }

    private function target(AuditEntry $entry): ?DatabaseDriver
    {
        $stores = ($this->stores)();
        $store = $entry->store === null ? $stores->default() : $stores->get(new StoreName($entry->store));

        return $store->database() ?? $stores->default()->database();
    }

    private function meta(AuditEntry $entry): ?string
    {
        if ($entry->meta === []) {
            return null;
        }

        try {
            return json_encode($entry->meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new DriverFailure('An audit entry\'s meta could not be encoded.', 0, $exception);
        }
    }
}
