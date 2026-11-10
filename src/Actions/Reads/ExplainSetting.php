<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Reads;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Data\SettingAddress;
use Simtabi\Laranail\Settings\Resolution\Explanation;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;

/** How a read resolves (§7.2), for a channel other than PHP: the same handle's `explain()`. */
final readonly class ExplainSetting
{
    public function __construct(
        private Settings $settings,
        private SettingTargets $targets,
    ) {}

    public function handle(SettingAddress $address, ?Actor $actor = null): Explanation
    {
        return $this->targets->handle($this->settings, $address, $actor)->explain($address->key);
    }
}
