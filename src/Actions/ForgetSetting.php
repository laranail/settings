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

/** Remove a scope's own value so reads fall through to the next scope or layer. */
final readonly class ForgetSetting
{
    public function __construct(
        private SettingWriter $writer,
        private WriteGuard $guard,
    ) {}

    /**
     * @param ?int $expectedVersion forget only while the row is at this version (0: no row)
     *
     * @return ?ChangeRequest the change request opened instead, when the setting needs approval
     */
    public function handle(SettingTarget $target, ?Actor $actor = null, ?int $expectedVersion = null): ?ChangeRequest
    {
        return $this->guard->run(Ability::Forget, $target, $actor, respectsLocks: true, write: function (bool $override) use ($target, $actor, $expectedVersion): array {
            $writer = $this->writer->causedBy($actor, $target->reason);
            $removed = match (true) {
                $expectedVersion !== null => $writer->forgetExpecting($target->store, $target->key, $target->locale, $target->owner, $target->definition, $expectedVersion, $override),
                $override                 => $writer->forgetOverridingLock($target->store, $target->key, $target->locale, $target->owner, $target->definition),
                default                   => $writer->forget($target->store, $target->key, $target->locale, $target->owner, $target->definition),
            };

            return [$removed?->version, null];
        }, proposal: new ChangeProposal($target, ChangeOperation::Forget));
    }
}
