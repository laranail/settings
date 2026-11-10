<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Actions\Approvals\ApprovalActions;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * `laranail::settings.prune-changes`: mark open change requests past `approvals.expire_after` as
 * expired, and delete decided ones older than `approvals.keep_days`. Runs as the system actor and
 * is audited.
 */
final class PruneChangesCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.prune-changes';

    protected $description = 'Expire overdue change requests and delete old decided ones';

    public function handle(ApprovalActions $approvals): int
    {
        try {
            $result = $approvals->prune->handle(Actor::system('laranail::settings.prune-changes'));
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Expired %d change request(s); deleted %d decided one(s).', $result['expired'], $result['deleted']));

        return self::SUCCESS;
    }
}
