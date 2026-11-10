<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Console;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Illuminate\Contracts\Config\Repository;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Channel;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Data\SettingAddress;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Settings\Resolution\Explanation;
use Simtabi\Laranail\Settings\Actions\Reads\ExplainSetting;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Services\Definitions\Definitions;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Show how one setting resolves: value, layer, type, version, flags and the latest audit entry.
 * Sensitive values print as `[protected]` unless `--reveal` is given.
 */
final class ShowCommand extends Command
{
    use SupportsNamespacedNames;

    protected $signature = 'laranail::settings.show
        {key : The setting key}
        {--store= : The store (default: the default store)}
        {--owner= : An owner scope, alias:id (default: the global value)}
        {--locale= : A locale (default: the unlocalised value)}
        {--reveal : Print a sensitive or encrypted value}';

    protected $description = 'Show how a setting resolves: value, layer, type, version, flags and last audit entry';

    public function handle(Settings $settings, ExplainSetting $explain, SettingTargets $targets, Definitions $definitions, Repository $config): int
    {
        try {
            $address = $this->address($config->string('laranail.settings.default', 'settings'));
            $actor = Actor::system('laranail::settings.show', Channel::Console);
            $store = $targets->store($address->store);
            $definition = $definitions->find($store, new Key($address->key));
            $audit = $this->latestAudit($settings, $address, $actor);
            $explanation = $explain->handle($address, $actor);
            $record = $this->record($store, $explanation, $address);
        } catch (SettingsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $lookup = $explanation->lookup;
        $sensitive = $definition?->isSensitive() === true || $record?->encrypted === true;

        $this->table(['Field', 'Value'], [
            ['Key', $address->key],
            ['Store', $address->store],
            ['Scope', Scope::of($address->ownerRef())->value],
            ['Locale', $address->locale === '' ? '-' : $address->locale],
            ['Value', $this->value($explanation, $sensitive)],
            ['Layer', $lookup->layer() ?? '-'],
            ['From scope', $lookup->scope()->value ?? '-'],
            ['Type', $record->type ?? $definition->type ?? '-'],
            ['Version', $record instanceof Record ? (string) $record->version : '-'],
            ['Locked', $this->flag($record instanceof Record ? $record->locked : $definition?->locked)],
            ['Encrypted', $this->flag($record instanceof Record ? $record->encrypted : $definition?->encrypted)],
            ['Sensitive', $this->flag($definition?->isSensitive())],
            ['Defined', $this->flag($definition instanceof Definition)],
            ['Last audit', $audit instanceof AuditEntry ? $this->describe($audit) : '-'],
        ]);

        foreach ($explanation->steps as $step) {
            $this->line('  ' . $step);
        }

        return self::SUCCESS;
    }

    private function address(string $default): SettingAddress
    {
        $key = $this->argument('key');
        $store = $this->option('store');
        $owner = $this->option('owner');
        $locale = $this->option('locale');

        return new SettingAddress(
            is_string($store) && $store !== '' ? $store : $default,
            is_string($key) ? $key : '',
            is_string($owner) && $owner !== ''
                ? Scope::fromString($owner)?->owner() ?? throw new InvalidIdentifier(sprintf('--owner: [%s] is not alias:id.', $owner))
                : null,
            is_string($locale) ? $locale : '',
        );
    }

    /** The newest audit entry for this key and scope, or null when the audit trail cannot be queried. */
    private function latestAudit(Settings $settings, SettingAddress $address, Actor $actor): ?AuditEntry
    {
        try {
            $query = $settings->as($actor)->store($address->store)->audit()->key($address->key);
            $owner = $address->ownerRef();
            $query = $owner instanceof OwnerRef ? $query->for($owner) : $query->global();

            return $query->get(1)[0] ?? null;
        } catch (SettingsException) {
            return null;
        }
    }

    /** The row behind the answer, from the layer that gave it; null for a default or a miss. */
    private function record(Store $store, Explanation $explanation, SettingAddress $address): ?Record
    {
        $scope = $explanation->lookup->scope();

        foreach ($store->layers as $layer) {
            if ($scope instanceof Scope && $layer->name === $explanation->lookup->layer()) {
                return $layer->driver->load($store->name, [$scope])->find($scope, new Key($address->key), new Locale($address->locale));
            }
        }

        return null;
    }

    private function value(Explanation $explanation, bool $sensitive): string
    {
        $lookup = $explanation->lookup;

        return match (true) {
            ! $lookup->isFound()                                                  => '(not set)',
            $lookup->isRedacted(), $sensitive && $this->option('reveal') !== true => '[protected]',
            default                                                               => (string) json_encode($lookup->value(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    private function flag(?bool $value): string
    {
        return match ($value) {
            true  => 'yes',
            false => 'no',
            null  => '-',
        };
    }

    private function describe(AuditEntry $entry): string
    {
        return sprintf(
            '%s %s by %s%s via %s at %s',
            $entry->action->value,
            $entry->outcome->value,
            $entry->actorType->value,
            $entry->actorId === null ? '' : ':' . $entry->actorAlias . ':' . $entry->actorId,
            $entry->channel->value,
            $entry->occurredAt->toIso8601String(),
        );
    }
}
