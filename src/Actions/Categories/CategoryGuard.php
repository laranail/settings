<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Categories;

use Closure;
use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\CategorySlug;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Authorization\AccessGuard;
use Simtabi\Laranail\Settings\Database\CategoryRepository;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;

/**
 * What every category change does around its work: authorize `manage_categories`, then run the
 * change, its events and its audit entry in one transaction on the store's connection. A failure
 * is audited after the rollback. Categories live in the database, so a store without an active
 * database layer refuses the change with {@see StoreNotWritable}.
 */
final readonly class CategoryGuard
{
    public function __construct(
        private AccessGuard $access,
        private Dispatcher $events,
    ) {}

    /**
     * @template T
     *
     * @param Closure(CategoryRepository, DatabaseDriver): T $work
     *
     * @return T
     */
    public function run(Store $store, CategorySlug $slug, ?Actor $actor, string $operation, object $before, object $after, Closure $work): mixed
    {
        $subject = new Subject($store->name, category: $slug->value);
        $meta = ['operation' => $operation, 'category' => $slug->value];

        $this->access->authorize($actor, Ability::ManageCategories, $subject);

        $database = $store->requireDatabase('; categories are kept in the database.');

        try {
            return $store->transaction(function () use ($store, $database, $work, $before, $after, $subject, $actor, $meta): mixed {
                $this->events->dispatch($before);
                $result = $work(new CategoryRepository($database, $store->name), $database);
                $this->access->auditor()->record(AuditAction::ManageCategories, AuditOutcome::Allowed, $subject, $actor, meta: $meta);
                $this->events->dispatch($after);

                return $result;
            });
        } catch (SettingsException $exception) {
            try {
                $this->access->auditor()->record(AuditAction::ManageCategories, AuditOutcome::Failed, $subject, $actor, meta: $meta + ['error' => $exception::class]);
            } catch (SettingsException) {
                // The original failure is what the caller needs; it is rethrown below.
            }

            throw $exception;
        }
    }
}
