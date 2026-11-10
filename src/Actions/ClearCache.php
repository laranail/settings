<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Cache\SettingsCache;

/**
 * Reset cached settings (design §7.5, `laranail::settings.clear-cache`): every bag, one store's,
 * or one owner's in one store. Needs `manage_stores` when an actor is present, and is audited as
 * a `manage_stores` entry naming what was reset. Values are never touched, only cached copies,
 * so the next read of anything reset costs one query per store again.
 *
 * Resetting replaces generation tokens; nothing is deleted from the cache store, and the
 * application's other cache entries are never touched. Returns false when caching is off.
 */
final readonly class ClearCache
{
    public function __construct(
        private AccessGuard $access,
        private ?SettingsCache $cache,
        private StoreName $defaultStore = new StoreName('settings'),
    ) {}

    /** @param ?Store $store null resets every store; with $owner, only that owner's bag */
    public function handle(?Store $store = null, ?OwnerRef $owner = null, ?Actor $actor = null): bool
    {
        $subject = new Subject($store->name ?? $this->defaultStore, owner: $owner);
        $this->access->authorize($actor, Ability::ManageStores, $subject);

        if (! $this->cache instanceof SettingsCache) {
            return false;
        }

        if (! $store instanceof Store) {
            $this->cache->forgetAll();
            $target = 'all';
        } elseif (! $owner instanceof OwnerRef) {
            $store->forgetCachedStore();
            $target = 'store';
        } else {
            $store->forgetCached($owner->scope());
            $target = 'scope';
        }

        $this->access->auditor()->record(AuditAction::ManageStores, AuditOutcome::Allowed, $subject, $actor, meta: ['operation' => 'cache.clear', 'target' => $target]);

        return true;
    }
}
