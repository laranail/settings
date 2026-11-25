<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Actions\RescopeOwners;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Rename an owner alias in stored values, history and change requests, after renaming it in the
 * morph map. `from` may be a class name.
 */
final class RescopeCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.rescope
        {from : The old alias (or class name)}
        {to : The new morph alias}';

    protected $description = 'Rename an owner alias in stored settings';

    public function handle(RescopeOwners $rescope): int
    {
        $from = $this->argument('from');
        $to = $this->argument('to');
        $from = is_string($from) ? $from : '';
        $to = is_string($to) ? $to : '';

        try {
            $result = $rescope->handle($from, $to, Actor::system('laranail::settings.rescope'));
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(trans_choice('laranail/settings::settings.console.rescoped', $result['history'], ['from' => $from, 'to' => $to, 'values' => $result['values'], 'changes' => $result['changes']]));

        return self::SUCCESS;
    }
}
