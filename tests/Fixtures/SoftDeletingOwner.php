<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Concerns\HasSettings;

/**
 * A soft-deleting owner: its settings survive a soft delete and go with a force delete.
 *
 * @property int $id
 */
final class SoftDeletingOwner extends Model implements Settable
{
    use HasSettings;
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'soft_owners';

    protected $guarded = [];
}
