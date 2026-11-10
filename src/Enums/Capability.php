<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Settings\Actions\QueryAudit;
use Simtabi\Laranail\Settings\Actions\SetSetting;
use Simtabi\Laranail\Settings\Actions\LockSetting;
use Simtabi\Laranail\Settings\Actions\ForgetSetting;
use Simtabi\Laranail\Settings\Actions\UnlockSetting;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Settings\Actions\RollbackSetting;
use Simtabi\Laranail\Settings\Actions\Reads\ListStores;
use Simtabi\Laranail\Settings\Actions\Reads\ReadHistory;
use Simtabi\Laranail\Settings\Actions\Reads\ReadSetting;
use Simtabi\Laranail\Settings\Actions\Reads\ReadSettings;
use Simtabi\Laranail\Settings\Actions\Transfer\DiffStore;
use Simtabi\Laranail\Settings\Actions\Reads\ExplainSetting;
use Simtabi\Laranail\Settings\Actions\Transfer\ExportStore;
use Simtabi\Laranail\Settings\Actions\Transfer\ImportStore;
use Simtabi\Laranail\Settings\Actions\Reads\ListDefinitions;
use Simtabi\Laranail\Settings\Actions\Approvals\CancelChange;
use Simtabi\Laranail\Settings\Actions\Approvals\QueryChanges;
use Simtabi\Laranail\Settings\Actions\Approvals\RejectChange;
use Simtabi\Laranail\Settings\Actions\Approvals\ApproveChange;
use Simtabi\Laranail\Settings\Actions\Approvals\ProposeChange;
use Simtabi\Laranail\Settings\Actions\Categories\MoveCategory;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;
use Simtabi\Laranail\Settings\Actions\Categories\CreateCategory;
use Simtabi\Laranail\Settings\Actions\Categories\DeleteCategory;
use Simtabi\Laranail\Settings\Actions\Categories\ListCategories;
use Simtabi\Laranail\Settings\Actions\Categories\RenameCategory;
use Simtabi\Laranail\Settings\Actions\Configuration\SaveDefinition;
use Simtabi\Laranail\Settings\Actions\Configuration\DeleteDefinition;
use Simtabi\Laranail\Settings\Actions\Configuration\SaveConfigurationEntry;
use Simtabi\Laranail\Settings\Actions\Configuration\DeleteConfigurationEntry;
use Simtabi\Laranail\Settings\Actions\Configuration\ListConfigurationEntries;

/**
 * Every capability the REST API exposes (§16.2, §16.10), each named for its route
 * (`laranail-settings.api.<value>`, and `laranail-settings.api.owners.<value>` for the owner
 * form) and backed by the actions the PHP API uses. An architecture test holds the routes, this
 * list and the controllers to each other, so no channel can grow a capability the others lack.
 */
enum Capability: string implements Enumerator
{
    use HasEnumeratorBehavior;

    #[Label('List stores')]
    case StoresIndex = 'stores.index';

    #[Label('List definitions')]
    case DefinitionsIndex = 'definitions.index';

    #[Label('List settings')]
    case SettingsIndex = 'settings.index';

    #[Label('Read a setting')]
    case SettingsShow = 'settings.show';

    #[Label('Write a setting')]
    case SettingsUpdate = 'settings.update';

    #[Label('Forget a setting')]
    case SettingsDestroy = 'settings.destroy';

    #[Label('Lock a setting')]
    case SettingsLock = 'settings.lock';

    #[Label('Unlock a setting')]
    case SettingsUnlock = 'settings.unlock';

    #[Label('Explain a setting')]
    case SettingsExplain = 'settings.explain';

    #[Label('A setting\'s history')]
    case SettingsHistory = 'settings.history';

    #[Label('Roll a setting back')]
    case SettingsRollback = 'settings.rollback';

    #[Label('The audit trail')]
    case AuditIndex = 'audit.index';

    #[Label('List change requests')]
    case ChangesIndex = 'changes.index';

    #[Label('Read a change request')]
    case ChangesShow = 'changes.show';

    #[Label('Propose a change')]
    case ChangesStore = 'changes.store';

    #[Label('Approve a change')]
    case ChangesApprove = 'changes.approve';

    #[Label('Reject a change')]
    case ChangesReject = 'changes.reject';

    #[Label('Cancel a change')]
    case ChangesCancel = 'changes.cancel';

    #[Label('List categories')]
    case CategoriesIndex = 'categories.index';

    #[Label('Create a category')]
    case CategoriesStore = 'categories.store';

    #[Label('Rename or move a category')]
    case CategoriesUpdate = 'categories.update';

    #[Label('Delete a category')]
    case CategoriesDestroy = 'categories.destroy';

    #[Label('List persisted configuration')]
    case ConfigurationIndex = 'configuration.index';

    #[Label('Save a persisted store or layer')]
    case ConfigurationUpdate = 'configuration.update';

    #[Label('Delete a persisted store or layer')]
    case ConfigurationDestroy = 'configuration.destroy';

    #[Label('Save a definition')]
    case DefinitionsUpdate = 'definitions.update';

    #[Label('Delete a definition')]
    case DefinitionsDestroy = 'definitions.destroy';

    #[Label('Export a store')]
    case TransferExport = 'transfer.export';

    #[Label('Import into a store')]
    case TransferImport = 'transfer.import';

    #[Label('Compare a store with an export')]
    case TransferDiff = 'transfer.diff';

    /**
     * The actions this capability runs, as the PHP API runs them.
     *
     * @return list<class-string>
     */
    public function actions(): array
    {
        return match ($this) {
            self::StoresIndex          => [ListStores::class],
            self::DefinitionsIndex     => [ListDefinitions::class],
            self::SettingsIndex        => [ReadSettings::class],
            self::SettingsShow         => [ReadSetting::class],
            self::SettingsUpdate       => [SetSetting::class],
            self::SettingsDestroy      => [ForgetSetting::class],
            self::SettingsLock         => [LockSetting::class],
            self::SettingsUnlock       => [UnlockSetting::class],
            self::SettingsExplain      => [ExplainSetting::class],
            self::SettingsHistory      => [ReadHistory::class],
            self::SettingsRollback     => [RollbackSetting::class],
            self::AuditIndex           => [QueryAudit::class],
            self::ChangesIndex         => [QueryChanges::class],
            self::ChangesShow          => [QueryChanges::class],
            self::ChangesStore         => [ProposeChange::class],
            self::ChangesApprove       => [ApproveChange::class],
            self::ChangesReject        => [RejectChange::class],
            self::ChangesCancel        => [CancelChange::class],
            self::CategoriesIndex      => [ListCategories::class],
            self::CategoriesStore      => [CreateCategory::class],
            self::CategoriesUpdate     => [RenameCategory::class, MoveCategory::class],
            self::CategoriesDestroy    => [DeleteCategory::class],
            self::ConfigurationIndex   => [ListConfigurationEntries::class],
            self::ConfigurationUpdate  => [SaveConfigurationEntry::class],
            self::ConfigurationDestroy => [DeleteConfigurationEntry::class],
            self::DefinitionsUpdate    => [SaveDefinition::class],
            self::DefinitionsDestroy   => [DeleteDefinition::class],
            self::TransferExport       => [ExportStore::class],
            self::TransferImport       => [ImportStore::class],
            self::TransferDiff         => [DiffStore::class],
        };
    }

    /** The `http.routes` group that switches this capability's routes on or off. */
    public function group(): string
    {
        return match ($this) {
            self::SettingsHistory, self::SettingsRollback => 'history',
            self::ChangesIndex, self::ChangesShow, self::ChangesStore,
            self::ChangesApprove, self::ChangesReject, self::ChangesCancel => 'approvals',
            self::DefinitionsUpdate, self::DefinitionsDestroy              => 'configuration',
            default                                                        => explode('.', $this->value)[0],
        };
    }

    /** Whether the capability has an owner form (`/owners/{alias}/{id}/settings/…`). */
    public function hasOwnerForm(): bool
    {
        return str_starts_with($this->value, 'settings.');
    }
}
