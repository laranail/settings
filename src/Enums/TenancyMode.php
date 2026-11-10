<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * How settings are kept apart per tenant (Amendment B, `tenancy.mode`).
 */
enum TenancyMode: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('None')]
    #[Description('One set of settings for the whole application. The default.')]
    case None = 'none';

    #[Label('Tenant column')]
    #[Description('One database: every values, categories and audit row carries the current tenant, and every query is limited to it.')]
    case Column = 'column';

    #[Label('Database per tenant')]
    #[Description('The tenancy package switches the database connection; the tables have no tenant column.')]
    case Database = 'database';

    /** Whether rows and queries carry a tenant column. */
    public function hasColumn(): bool
    {
        return $this === self::Column;
    }
}
