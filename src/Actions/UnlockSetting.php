<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\SettingTarget;
use Simtabi\Laranail\Settings\Stores\SettingWriter;

/**
 * Unlock one scope's row against writes: needs `unlock`.
 */
final readonly class UnlockSetting
{
    public function __construct(
        private SettingWriter $writer,
        private WriteGuard $guard,
    ) {}

    public function handle(SettingTarget $target, ?Actor $actor = null): void
    {
        $this->guard->run(Ability::Unlock, $target, $actor, respectsLocks: false, write: function () use ($target): array {
            $after = $this->writer->unlock($target->store, $target->key, $target->locale, $target->owner, $target->definition);

            return [$after->version > 1 ? $after->version - 1 : null, $after->version];
        });
    }
}
