<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Database\Factories;

use DateTimeInterface;
use Simtabi\Laranail\Settings\Enums\Channel;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Models\ModelContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Simtabi\Laranail\Settings\Models\SettingAuditEntry;

/**
 * Audit rows for tests of audit queries and retention. Real entries come from the audit sinks.
 *
 * @extends Factory<SettingAuditEntry>
 */
final class SettingAuditEntryFactory extends Factory
{
    protected $model = SettingAuditEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'occurred_at' => now(),
            'action'      => AuditAction::Update->value,
            'outcome'     => AuditOutcome::Allowed->value,
            'store'       => ModelContext::defaultStore()->value,
            'scope'       => 'global',
            'key'         => 'test.key',
            'actor_type'  => ActorType::System->value,
            'channel'     => Channel::Console->value,
        ];
    }

    public function action(AuditAction $action, AuditOutcome $outcome = AuditOutcome::Allowed): static
    {
        return $this->state(['action' => $action->value, 'outcome' => $outcome->value]);
    }

    public function by(string $alias, int|string $id): static
    {
        return $this->state(['actor_type' => ActorType::User->value, 'actor_alias' => $alias, 'actor_id' => (string) $id]);
    }

    public function at(DateTimeInterface $when): static
    {
        return $this->state(['occurred_at' => $when]);
    }
}
