<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\SettingTarget;
use Simtabi\Laranail\Settings\Stores\SettingWriter;

/**
 * Lock one scope's row against writes: needs `lock`.
 */
final readonly class LockSetting
{
    public function __construct(
        private SettingWriter $writer,
        private WriteGuard $guard,
    ) {}

    public function handle(SettingTarget $target, ?Actor $actor = null): void
    {
        $this->guard->run(Ability::Lock, $target, $actor, respectsLocks: false, write: function () use ($target): array {
            $after = $this->writer->lock($target->store, $target->key, $target->locale, $target->owner, $target->definition);

            return [$after->version > 1 ? $after->version - 1 : null, $after->version];
        });
    }
}
