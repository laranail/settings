<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Configuration;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Database\DefinitionsRepository;
use Simtabi\Laranail\Settings\Services\Definitions\DatabaseDefinitions;

/**
 * Delete a definition saved through the API (§16.11): needs `manage_definitions`; definitions from
 * code or configuration are never deleted here. Audited. Returns whether one was there.
 */
final readonly class DeleteDefinition
{
    public function __construct(
        private DatabaseDefinitions $definitions,
        private ValueEncoder $encoder,
        private AccessGuard $access,
    ) {}

    public function handle(Store $store, string $key, ?Actor $actor = null): bool
    {
        $settingKey = new Key($key);
        $subject = new Subject($store->name, $settingKey);
        $this->access->authorize($actor, Ability::ManageDefinitions, $subject);

        $database = $store->database();

        if (! $database instanceof DatabaseDriver || ! $database->shape()->definitionsTable) {
            throw new InvalidConfiguration(sprintf('Store [%s] has no definitions table.', $store->name));
        }

        $deleted = new DefinitionsRepository($database, $this->encoder)->delete($store->name, $settingKey);
        $this->definitions->forget($store);
        $this->access->auditor()->record(AuditAction::ManageDefinitions, AuditOutcome::Allowed, $subject, $actor, meta: ['operation' => 'delete', 'existed' => $deleted]);

        return $deleted;
    }
}
