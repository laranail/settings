<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\SettingWrite;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Data\ChangeProposal;
use Simtabi\Laranail\Settings\Enums\ChangeOperation;
use Simtabi\Laranail\Settings\Services\Stores\SettingWriter;

/** Store a value. A write naming an expected version is checked against it and never retried. */
final readonly class SetSetting
{
    public function __construct(
        private SettingWriter $writer,
        private WriteGuard $guard,
    ) {}

    /** @return ?ChangeRequest the change request opened instead, when the setting needs approval */
    public function handle(SettingWrite $write, ?Actor $actor = null): ?ChangeRequest
    {
        $target = $write->target;

        return $this->guard->run(Ability::Update, $target, $actor, respectsLocks: true, write: function (bool $override) use ($target, $write, $actor): array {
            $after = match (true) {
                $write->expectedVersion !== null => $this->writer->causedBy($actor, $target->reason)->setExpecting($target->store, $target->key, $target->locale, $target->owner, $target->definition, $write->value, $write->expectedVersion, $override),
                $override                        => $this->writer->causedBy($actor, $target->reason)->setOverridingLock($target->store, $target->key, $target->locale, $target->owner, $target->definition, $write->value),
                default                          => $this->writer->causedBy($actor, $target->reason)->set($target->store, $target->key, $target->locale, $target->owner, $target->definition, $write->value),
            };

            return [$after->version > 1 ? $after->version - 1 : null, $after->version];
        }, proposal: new ChangeProposal($target, ChangeOperation::Set, $write->value));
    }
}
