<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models;

use Override;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Simtabi\Laranail\Settings\Models\Concerns\UsesSettingsSchema;
use Simtabi\Laranail\Settings\Database\Factories\SettingDefinitionFactory;

/**
 * A row of the optional definitions table (§4.4, `definitions.table`). Definitions are shared
 * by every tenant (they describe settings, they do not hold values), so this table has no tenant
 * column. `is_system` rows are synced from code and config and are never read over code.
 *
 * @property int|string $id
 * @property string $store
 * @property string $key
 * @property string $slug
 * @property bool $is_system
 */
final class SettingDefinition extends Model
{
    /** @use HasFactory<SettingDefinitionFactory> */
    use HasFactory;

    use UsesSettingsSchema;

    protected $guarded = [];

    #[Override]
    public function getTable(): string
    {
        return ModelContext::shape()->names->definitions();
    }

    #[Override]
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? ModelContext::connectionOf(ModelContext::defaultStore());
    }

    /** Definitions are shared by every tenant. */
    public function usesTenantColumn(): bool
    {
        return false;
    }

    /** @return BelongsTo<SettingCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SettingCategory::class, 'category_id');
    }

    protected static function newFactory(): SettingDefinitionFactory
    {
        return SettingDefinitionFactory::new();
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'rules'          => 'array',
            'overridable_by' => 'array',
            'meta'           => 'array',
            'is_encrypted'   => 'boolean',
            'is_public'      => 'boolean',
            'is_locked'      => 'boolean',
            'autoload'       => 'boolean',
            'inherits'       => 'boolean',
            'is_system'      => 'boolean',
        ];
    }
}
