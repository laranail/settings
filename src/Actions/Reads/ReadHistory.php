<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Reads;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\HistoryPage;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Data\SettingAddress;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;

/** Read a page of one setting's history through the handle's `history()`. */
final readonly class ReadHistory
{
    public function __construct(
        private Settings $settings,
        private SettingTargets $targets,
    ) {}

    public function handle(SettingAddress $address, ?Actor $actor = null, int $perPage = 50, ?string $cursor = null): HistoryPage
    {
        $history = $this->targets->handle($this->settings, $address, $actor)->history()->key($address->key);

        return ($address->owner === null ? $history->global() : $history)->paginate($perPage, $cursor);
    }
}
