<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Typed;

use Closure;
use LogicException;
use Illuminate\Database\Migrations\Migration;

/**
 * A migration that changes settings rather than tables: renames, moves, deletions and new
 * defaults, run in order with the application's other migrations.
 *
 *     return new class extends SettingsMigration
 *     {
 *         public function up(): void
 *         {
 *             $this->settings()->rename('mail.from', 'mail.from_address');
 *         }
 *
 *         public function down(): void
 *         {
 *             $this->settings()->rename('mail.from_address', 'mail.from');
 *         }
 *     };
 *
 * Migrations are built with `new`, so the provider supplies the migrator through
 * {@see resolveMigratorUsing()}.
 */
abstract class SettingsMigration extends Migration
{
    /** @var (Closure(): SettingsMigrator)|null */
    private static ?Closure $migrator = null;

    /** @param Closure(): SettingsMigrator $migrator */
    public static function resolveMigratorUsing(Closure $migrator): void
    {
        self::$migrator = $migrator;
    }

    protected function settings(): SettingsMigrator
    {
        return (self::$migrator ?? throw new LogicException('Settings migrations need laranail/settings\' service provider to be registered.'))();
    }
}
