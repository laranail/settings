<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\SettingWrite;
use Simtabi\Laranail\Settings\Stores\SettingWriter;

/**
 * Store a value: needs `update` (and `override_lock` over a locked row the store lets be
 * overridden). Shared by the PHP API and, later, the console and REST channels.
 */
final readonly class SetSetting
{
    public function __construct(
        private SettingWriter $writer,
        private WriteGuard $guard,
    ) {}

    public function handle(SettingWrite $write, ?Actor $actor = null): void
    {
        $target = $write->target;

        $this->guard->run(Ability::Update, $target, $actor, respectsLocks: true, write: function (bool $override) use ($target, $write): array {
            $after = $override
                ? $this->writer->setOverridingLock($target->store, $target->key, $target->locale, $target->owner, $target->definition, $write->value)
                : $this->writer->set($target->store, $target->key, $target->locale, $target->owner, $target->definition, $write->value);

            return [$after->version > 1 ? $after->version - 1 : null, $after->version];
        });
    }
}
