<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/** What an audit entry records: every {@see Ability}, plus configuration and maintenance operations. */
enum AuditAction: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('View settings')]
    case View = 'view';

    #[Label('View sensitive settings')]
    case ViewSensitive = 'view_sensitive';

    #[Label('Update settings')]
    case Update = 'update';

    #[Label('Forget settings')]
    case Forget = 'forget';

    #[Label('Lock settings')]
    case Lock = 'lock';

    #[Label('Unlock settings')]
    case Unlock = 'unlock';

    #[Label('Write locked settings')]
    case OverrideLock = 'override_lock';

    #[Label('Approve changes')]
    case Approve = 'approve';

    #[Label('View history')]
    case ViewHistory = 'view_history';

    #[Label('Roll back')]
    case Rollback = 'rollback';

    #[Label('View the audit trail')]
    case ViewAudit = 'view_audit';

    #[Label('Export')]
    case Export = 'export';

    #[Label('Import')]
    case Import = 'import';

    #[Label('Promote a store')]
    case Promote = 'promote';

    #[Label('Manage categories')]
    case ManageCategories = 'manage_categories';

    #[Label('Manage definitions')]
    case ManageDefinitions = 'manage_definitions';

    #[Label('Manage stores')]
    case ManageStores = 'manage_stores';

    #[Label('Prune orphans and old audit entries')]
    case Prune = 'prune';

    #[Label('Configure')]
    case Configure = 'configure';

    #[Label('Propose a change')]
    case Propose = 'propose';

    #[Label('Reject a change')]
    case Reject = 'reject';

    #[Label('Cancel a change')]
    case Cancel = 'cancel';

    #[Label('Re-encrypt values')]
    case Reencrypt = 'reencrypt';

    #[Label('Rename an owner alias')]
    case Rescope = 'rescope';

    #[Label('Migrate settings')]
    case Migrate = 'migrate';

    public static function fromAbility(Ability $ability): self
    {
        return self::from($ability->value);
    }
}
