<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Reads;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\SettingView;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Data\SettingAddress;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;

/**
 * Read one setting through the settings handle, with its row version for the ETag. A sensitive
 * value the actor may not see is redacted rather than refused.
 */
final readonly class ReadSetting
{
    public function __construct(
        private Settings $settings,
        private SettingTargets $targets,
    ) {}

    /** @throws AccessDenied when the actor may not view the key at all */
    public function handle(SettingAddress $address, ?Actor $actor = null): SettingView
    {
        $handle = $this->targets->handle($this->settings, $address, $actor);
        $explanation = $handle->explain($address->key);
        $lookup = $explanation->lookup;
        $version = $this->targets->version($address);

        if ($lookup->isRedacted()) {
            return new SettingView($address, true, null, $lookup->layer(), $lookup->scope()?->value, $version, redacted: true);
        }

        return new SettingView($address, $lookup->isFound(), $lookup->isFound() ? $lookup->value() : null, $lookup->layer(), $lookup->scope()?->value, $version);
    }
}
