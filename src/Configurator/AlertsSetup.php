<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

/** `alerts.*`, inside `SettingsSetup::alerts()`. */
final readonly class AlertsSetup
{
    public function __construct(private SetupValues $values) {}

    /** `alerts.enabled`: send the configured alert notifications. */
    public function enabled(bool $enabled = true): self
    {
        $this->values->set('alerts.enabled', $enabled);

        return $this;
    }

    /** `alerts.throttle`: seconds between two alerts for one rule. */
    public function throttle(int $seconds): self
    {
        $this->values->set('alerts.throttle', SetupValues::atLeast('alerts()->throttle()', 'alerts.throttle', $seconds, 0));

        return $this;
    }
}
