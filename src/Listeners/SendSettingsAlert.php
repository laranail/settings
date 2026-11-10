<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Listeners;

use Throwable;
use Psr\Log\LoggerInterface;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Support\Clock;
use Simtabi\Laranail\Settings\Data\AlertRule;
use Simtabi\Laranail\Settings\Data\StoreName;
use Illuminate\Contracts\Translation\Translator;
use Simtabi\Laranail\Settings\Data\AlertContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Simtabi\Laranail\Settings\Events\AuditPruned;
use Simtabi\Laranail\Settings\Events\ChangeStale;
use Simtabi\Laranail\Settings\Events\ScopeFlushed;
use Simtabi\Laranail\Settings\Events\SettingSaved;
use Illuminate\Contracts\Cache\Repository as Cache;
use Simtabi\Laranail\Settings\Events\CategoryMoved;
use Simtabi\Laranail\Settings\Events\CategorySaved;
use Simtabi\Laranail\Settings\Events\ChangeApplied;
use Simtabi\Laranail\Settings\Events\HistoryPruned;
use Simtabi\Laranail\Settings\Events\OrphansPruned;
use Simtabi\Laranail\Settings\Events\SettingLocked;
use Simtabi\Laranail\Settings\Events\StorePromoted;
use Simtabi\Laranail\Settings\Events\ChangeRejected;
use Simtabi\Laranail\Settings\Events\AccessWasDenied;
use Simtabi\Laranail\Settings\Events\CategoryDeleted;
use Simtabi\Laranail\Settings\Events\CategoryRenamed;
use Simtabi\Laranail\Settings\Events\ChangeRequested;
use Simtabi\Laranail\Settings\Events\SettingUnlocked;
use Simtabi\Laranail\Settings\Events\SettingForgotten;
use Simtabi\Laranail\Settings\Events\DefinitionsSynced;
use Simtabi\Laranail\Settings\Events\SettingRolledBack;
use Simtabi\Laranail\Settings\Notifications\SettingsAlert;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Events\VerificationWasRequired;
use Simtabi\Laranail\Settings\Services\Definitions\Definitions;
use Illuminate\Contracts\Notifications\Dispatcher as Notifications;

/**
 * Sends `laranail.settings.alerts`: for each rule an event matches, one {@see SettingsAlert} to
 * the rule's mail addresses and Slack webhook, and one to its database notifiable. Alerts carry
 * identifiers only, never a value.
 *
 * Throttled per rule and per setting: after a rule alerts about one key (or one store, for
 * events without a key) it stays quiet about it for `alerts.throttle` seconds, so a storm of
 * writes produces one alert. The throttle is an atomic cache `add()`; when the cache fails the
 * alert is sent anyway (a missed alert is worse than a repeated one) and the failure is logged.
 * An alert that cannot be sent (a mail transport down, a notifiable's table missing) is logged,
 * never thrown: the change it reports has already committed.
 */
final readonly class SendSettingsAlert
{
    /** The events an alert rule can name: everything that happened, none of the `-ing` events. */
    public const array EVENTS = [
        SettingSaved::class,
        SettingForgotten::class,
        SettingLocked::class,
        SettingUnlocked::class,
        SettingRolledBack::class,
        ScopeFlushed::class,
        StorePromoted::class,
        CategorySaved::class,
        CategoryDeleted::class,
        CategoryMoved::class,
        CategoryRenamed::class,
        DefinitionsSynced::class,
        AuditPruned::class,
        HistoryPruned::class,
        OrphansPruned::class,
        AccessWasDenied::class,
        VerificationWasRequired::class,
        ChangeRequested::class,
        ChangeApplied::class,
        ChangeRejected::class,
        ChangeStale::class,
    ];

    /**
     * @param list<AlertRule> $rules
     * @param Definitions $definitions whether a store's key is sensitive or encrypted (code and runtime definitions only)
     */
    public function __construct(
        private Notifications $notifications,
        private array $rules,
        private Definitions $definitions,
        private Clock $clock,
        private LoggerInterface $logger,
        private Translator $translator,
        private ?Cache $cache = null,
        private int $throttle = 0,
        private string $prefix = 'laranail-settings',
    ) {}

    public function handle(object $event): void
    {
        if ($this->rules === []) {
            return;
        }

        $probe = AlertContext::fromEvent($event, false, $this->clock->now());
        $context = AlertContext::fromEvent($event, $this->sensitive($probe->store, $probe->key), $probe->occurredAt);

        foreach ($this->rules as $index => $rule) {
            if (! $rule->matches($event, $context) || $this->throttled($index, $context)) {
                continue;
            }

            try {
                $this->send($rule, $context);
            } catch (Throwable $exception) {
                // The change has committed; a failing alert must not turn it into an error.
                $this->logger->error(sprintf('laranail/settings: alert rule %d could not be sent: %s', $index, $exception->getMessage()), ['event' => $context->event, 'store' => $context->store, 'key' => $context->key]);
            }
        }
    }

    private function send(AlertRule $rule, AlertContext $context): void
    {
        $anonymous = new AnonymousNotifiable;
        $channels = [];

        if ($rule->mail !== []) {
            $anonymous->route('mail', $rule->mail);
            $channels[] = 'mail';
        }

        if ($rule->slack !== null && SettingsAlert::slackAvailable()) {
            $anonymous->route('slack', $rule->slack);
            $channels[] = 'slack';
        }

        if ($channels !== []) {
            $this->deliver($anonymous, new SettingsAlert($context, $channels, texts: $this->texts()), $rule->queue);
        }

        if ($rule->database !== null) {
            $notifiable = $this->notifiable($rule->database);

            if ($notifiable instanceof Model) {
                $this->deliver($notifiable, new SettingsAlert($context, ['database'], texts: $this->texts()), $rule->queue);
            }
        }
    }

    /**
     * The alert's labels and footer in the current locale.
     *
     * @return array<string, string>
     */
    private function texts(): array
    {
        return SettingsAlert::texts($this->translator);
    }

    /** Whether the event's key is sensitive or encrypted, by its code or runtime definition. */
    private function sensitive(?string $store, ?string $key): bool
    {
        if ($store === null || $key === null) {
            return false;
        }

        try {
            return $this->definitions->findInCode(new StoreName($store), new Key($key))?->isSensitive() ?? false;
        } catch (SettingsException) {
            return false;
        }
    }

    private function deliver(object $notifiable, SettingsAlert $alert, ?string $queue): void
    {
        if ($queue === null) {
            $this->notifications->sendNow($notifiable, $alert);

            return;
        }

        $this->notifications->send($notifiable, $alert->onQueue($queue));
    }

    /** @param array{type: class-string<Model>, id: int|string} $target */
    private function notifiable(array $target): ?Model
    {
        $model = $target['type']::query()->find($target['id']);

        if (! $model instanceof Model) {
            $this->logger->warning(sprintf('laranail/settings: the alert notifiable [%s:%s] does not exist; the database alert was not sent.', $target['type'], $target['id']));

            return null;
        }

        return $model;
    }

    private function throttled(int $index, AlertContext $context): bool
    {
        if (! $this->cache instanceof Cache || $this->throttle < 1) {
            return false;
        }

        $key = sprintf('%s:alerts:%d:%s', $this->prefix, $index, sha1(implode('|', [$context->event, $context->store, $context->scope, $context->key, $context->locale])));

        try {
            return ! $this->cache->add($key, true, $this->throttle);
        } catch (Throwable $exception) {
            $this->logger->warning(sprintf('laranail/settings: the alert throttle could not reach the cache (%s); sending the alert anyway.', $exception->getMessage()));

            return false;
        }
    }
}
