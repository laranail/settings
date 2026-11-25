<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Data\SettingTarget;
use Simtabi\Laranail\Settings\Data\ChangeProposal;
use Simtabi\Laranail\Settings\Enums\ChangeOperation;
use Simtabi\Laranail\Settings\Services\Stores\SettingWriter;

/** Unlock one scope's row. */
final readonly class UnlockSetting
{
    public function __construct(
        private SettingWriter $writer,
        private WriteGuard $guard,
    ) {}

    /** @return ?ChangeRequest the change request opened instead, when the setting needs approval */
    public function handle(SettingTarget $target, ?Actor $actor = null): ?ChangeRequest
    {
        return $this->guard->run(Ability::Unlock, $target, $actor, respectsLocks: false, write: function () use ($target, $actor): array {
            $after = $this->writer->causedBy($actor, $target->reason)->unlock($target->store, $target->key, $target->locale, $target->owner, $target->definition);

            return [$after->version > 1 ? $after->version - 1 : null, $after->version];
        }, proposal: new ChangeProposal($target, ChangeOperation::Unlock));
    }
}
