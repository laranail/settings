<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Facades\Settings as SettingsFacade;

/**
 * Makes an Eloquent model an owner of settings. Use it on a model that implements `Settable`:
 *
 *     class User extends Model implements Settable
 *     {
 *         use HasSettings;
 *     }
 *
 *     $user->settings()->get('ui.theme');
 *     User::query()->withSettings()->paginate();    // one query per store for the page
 *
 * The owner is the model's morph alias and key; a model without a morph map entry is refused.
 * Deleting the model deletes its settings, and a soft-deleted model keeps them until it is
 * force-deleted. Bulk deletes fire no model events, so run `prune-orphans` after them.
 *
 * @phpstan-require-extends Model
 */
trait HasSettings
{
    public static function bootHasSettings(): void
    {
        static::deleted(static function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting() !== true) {
                return;
            }

            try {
                SettingsFacade::for(new OwnerRef($model->getMorphClass(), self::ownerKey($model)))->asSystem('owner deleted')->flush();
            } catch (OwnerNotAllowed|InvalidIdentifier) {
                // No store holds settings for this alias, so there is nothing to remove.
            }
        });
    }

    /** This model, as an owner of settings: its morph alias and key. */
    public function settingsOwner(): OwnerRef
    {
        return new OwnerRef($this->getMorphClass(), self::ownerKey($this));
    }

    /**
     * Owners this one inherits from, nearest first. Build the list from foreign keys so no model
     * is loaded: `return OwnerRef::list(['team' => $this->team_id]);`.
     *
     * @return list<OwnerRef>
     */
    public function settingsParents(): array
    {
        return [];
    }

    /** A settings handle for this model. */
    public function settings(): Settings
    {
        return SettingsFacade::for($this);
    }

    /**
     * Eager-load the settings of every model the query returns, one read per store.
     *
     * @param Builder<static> $query
     */
    public function scopeWithSettings(Builder $query): void
    {
        $query->afterQuery(static function (mixed $models): mixed {
            if ($models instanceof Collection) {
                SettingsFacade::preload(array_filter($models->all(), static fn (mixed $model): bool => $model instanceof Settable));
            }

            return $models;
        });
    }

    private static function ownerKey(Model $model): int|string
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? $key : throw new OwnerNotAllowed(sprintf('A [%s] without a scalar key cannot own settings.', $model::class));
    }
}
