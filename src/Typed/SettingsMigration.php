<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Typed;

use Closure;
use LogicException;
use Illuminate\Database\Migrations\Migration;

/**
 * A database migration that changes settings rather than tables (G-17): renames, moves, deletions
 * and new defaults, run with the application's other migrations and in their order.
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
 * A migration is built with `new`, so nothing can be injected: the service provider hands this
 * class the migrator through {@see resolveMigratorUsing()}, as it hands the models their context.
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
