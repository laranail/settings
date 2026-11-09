<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Concerns\HasSettings;

/**
 * A model that owns settings, stored in the `owners` table, with a team as its parent.
 *
 * @property int $id
 * @property int|null $team_id
 */
final class SettingsOwner extends Model implements Settable
{
    use HasSettings;

    public $timestamps = false;

    protected $table = 'owners';

    protected $guarded = [];

    /** @return list<OwnerRef> */
    public function settingsParents(): array
    {
        return OwnerRef::list(['team' => $this->team_id]);
    }
}
