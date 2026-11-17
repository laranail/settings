<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

/** `audit.*` and `history.*`, inside `SettingsSetup::audit()`. */
final readonly class AuditSetup
{
    public function __construct(private SetupValues $values) {}

    /** `audit.keep_days`: days audit entries are kept before pruning. */
    public function keepDays(int $days): self
    {
        $this->values->set('audit.keep_days', SetupValues::atLeast('audit()->keepDays()', 'audit.keep_days', $days, 1));

        return $this;
    }

    /** `history.keep_days` and `history.keep_versions` (null: no version limit). */
    public function history(int $keepDays, ?int $keepVersions = null): self
    {
        $this->values->set('history.keep_days', SetupValues::atLeast('audit()->history()', 'history.keep_days', $keepDays, 1));
        $this->values->set('history.keep_versions', $keepVersions === null ? null : SetupValues::atLeast('audit()->history()', 'history.keep_versions', $keepVersions, 1));

        return $this;
    }
}
