<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Actions\ReencryptSettings;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * `laranail::settings.reencrypt`: after rotating APP_KEY (with the old key in APP_PREVIOUS_KEYS),
 * encrypt every stored secret again under the new key: live values, history and change requests.
 * Exits 1 when a value could be read with no key (it is left as it was).
 */
final class ReencryptCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.reencrypt';

    protected $description = 'Encrypt every stored secret again under the current key';

    public function handle(ReencryptSettings $reencrypt): int
    {
        try {
            $result = $reencrypt->handle(Actor::system('laranail::settings.reencrypt'));
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(trans_choice('laranail/settings::settings.console.reencrypted', $result['history'], ['values' => $result['values'], 'changes' => $result['changes']]));

        if ($result['failed'] > 0) {
            $this->error(__('laranail/settings::settings.console.reencrypt_failed', ['failed' => $result['failed']]));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
