<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\SettingTarget;
use Simtabi\Laranail\Settings\Services\Stores\SettingWriter;

/**
 * Remove a scope's own value so reads fall through again: needs `forget` (and `override_lock`
 * over a locked row the store lets be overridden).
 */
final readonly class ForgetSetting
{
    public function __construct(
        private SettingWriter $writer,
        private WriteGuard $guard,
    ) {}

    public function handle(SettingTarget $target, ?Actor $actor = null): void
    {
        $this->guard->run(Ability::Forget, $target, $actor, respectsLocks: true, write: function (bool $override) use ($target, $actor): array {
            $removed = $override
                ? $this->writer->causedBy($actor, $target->reason)->forgetOverridingLock($target->store, $target->key, $target->locale, $target->owner, $target->definition)
                : $this->writer->causedBy($actor, $target->reason)->forget($target->store, $target->key, $target->locale, $target->owner, $target->definition);

            return [$removed?->version, null];
        });
    }
}
