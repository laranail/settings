<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models;

use Override;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\Settings\Enums\IdType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Simtabi\Laranail\Settings\Models\Concerns\UsesSettingsSchema;
use Simtabi\Laranail\Settings\Database\Factories\SettingAuditEntryFactory;

/**
 * A row of the append-only audit table, for factories and tests. Never holds a value.
 *
 * @property string $id
 * @property string $action
 * @property string $outcome
 */
final class SettingAuditEntry extends Model
{
    /** @use HasFactory<SettingAuditEntryFactory> */
    use HasFactory;

    use UsesSettingsSchema;

    public $timestamps = false;

    protected $guarded = [];

    #[Override]
    public function getTable(): string
    {
        return ModelContext::shape()->names->audit();
    }

    #[Override]
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? ModelContext::connectionOf(ModelContext::defaultStore());
    }

    protected static function newFactory(): SettingAuditEntryFactory
    {
        return SettingAuditEntryFactory::new();
    }

    /** Always a ULID, whatever `ids.type` says. */
    protected function idType(): IdType
    {
        return IdType::Ulid;
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'meta' => 'array', 'version_from' => 'integer', 'version_to' => 'integer'];
    }
}
