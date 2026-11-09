<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Authorization\AccessGuard;

/**
 * The write actions a settings handle delegates to, held together so a handle is built from
 * one collaborator rather than four.
 */
final readonly class SettingActions
{
    public function __construct(
        public SetSetting $set,
        public ForgetSetting $forget,
        public LockSetting $lock,
        public UnlockSetting $unlock,
    ) {}

    /** The four actions over one writer, enforced by one access guard. */
    public static function using(SettingWriter $writer, AccessGuard $access): self
    {
        $guard = new WriteGuard($access, $writer);

        return new self(
            new SetSetting($writer, $guard),
            new ForgetSetting($writer, $guard),
            new LockSetting($writer, $guard),
            new UnlockSetting($writer, $guard),
        );
    }
}
