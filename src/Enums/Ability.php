<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * Everything an actor can be authorized to do. Each case is checked as the gate
 * `laranail-settings.<value>` by the gate authorizer and, for a token-bearing actor, must also be
 * among the token's abilities as `settings:<value>` (or `settings:*`).
 */
enum Ability: string implements Enumerator
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

    /** The gate the gate authorizer asks for this ability. */
    public function gateName(): string
    {
        return 'laranail-settings.' . $this->value;
    }

    /** The token ability a token-bearing actor must hold for this ability. */
    public function tokenAbility(): string
    {
        return 'settings:' . $this->value;
    }

    /** Whether the ability only reads, so step-up verification never applies to it. */
    public function isRead(): bool
    {
        return in_array($this, [self::View, self::ViewSensitive, self::ViewHistory, self::ViewAudit], true);
    }
}
