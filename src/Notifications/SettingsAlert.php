<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Notifications;

use ReflectionClass;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Simtabi\Laranail\Settings\Data\AlertContext;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * An alert about a settings event, sent through Laravel's notification channels: mail, the
 * database channel, and Slack when laravel/slack-notification-channel is installed. It carries an
 * {@see AlertContext}, which holds identifiers only (store, key, scope, actor, outcome, time) and
 * never a setting's value, so no channel can show one. Queued on the rule's queue when it names
 * one; sent at once otherwise.
 */
final class SettingsAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The legacy Slack message of laravel/slack-notification-channel (incoming webhooks). Kept as
     * a string, split so tooling does not turn it into a `::class` of a class that is not installed.
     */
    public const string SLACK_MESSAGE = 'Illuminate\\Notifications\\Messages\\' . 'SlackMessage';

    /**
     * @param list<string> $channels the channels this copy is sent on
     * @param string $slackMessage the Slack message class to build (the Slack channel package's, by default)
     */
    public function __construct(
        public readonly AlertContext $context,
        public readonly array $channels,
        public readonly string $slackMessage = self::SLACK_MESSAGE,
    ) {}

    /** Whether Slack alerts can be sent (the Slack channel package is installed). */
    public static function slackAvailable(): bool
    {
        return class_exists(self::SLACK_MESSAGE);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = new MailMessage()
            ->subject($this->context->headline())
            ->line($this->context->headline() . '.');

        foreach ($this->lines() as $label => $value) {
            $mail->line(sprintf('%s: %s', $label, $value));
        }

        return $mail->line('Values are never included in alerts; read the setting or its history to see what changed.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->context->toArray();
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }

    /**
     * The Slack message (incoming webhook), only when the Slack channel package is installed;
     * the alert is never routed to Slack otherwise.
     */
    public function toSlack(object $notifiable): ?object
    {
        if (! class_exists($this->slackMessage)) {
            return null;
        }

        $message = new ReflectionClass($this->slackMessage)->newInstance();
        $text = $this->context->headline() . "\n" . implode("\n", array_map(
            static fn (string $label, string $value): string => sprintf('%s: %s', $label, $value),
            array_keys($this->lines()),
            $this->lines(),
        ));

        if (method_exists($message, 'content')) {
            $message->content($text);
        }

        return $message;
    }

    /** @return array<string, string> */
    private function lines(): array
    {
        $lines = [
            'Event'   => $this->context->event,
            'Store'   => $this->context->store ?? '-',
            'Key'     => $this->context->key ?? '-',
            'Scope'   => $this->context->scope ?? '-',
            'Locale'  => $this->context->locale === null || $this->context->locale === '' ? '-' : $this->context->locale,
            'Actor'   => $this->context->actor,
            'Outcome' => $this->context->outcome,
            'At'      => $this->context->occurredAt->toIso8601String(),
        ];

        foreach ($this->context->details as $name => $value) {
            $lines[ucfirst(str_replace('_', ' ', $name))] = is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? '-');
        }

        return $lines;
    }
}
