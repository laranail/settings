<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Notifications;

use ReflectionClass;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Translation\Translator;
use Simtabi\Laranail\Settings\Data\AlertContext;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A notification about a settings event, sent by mail, database or Slack. It carries identifiers
 * only, never a setting's value.
 */
final class SettingsAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /** Kept as a split string so tooling does not turn it into a `::class` of an optional class. */
    public const string SLACK_MESSAGE = 'Illuminate\\Notifications\\Messages\\' . 'SlackMessage';

    public const string VIEW = 'laranail/settings::mail.alert';

    private const string NAMESPACE = 'laranail/settings::settings.';

    private const array KEYS = [
        'alert.labels.event',
        'alert.labels.store',
        'alert.labels.key',
        'alert.labels.scope',
        'alert.labels.locale',
        'alert.labels.actor',
        'alert.labels.outcome',
        'alert.labels.at',
        'alert.yes',
        'alert.no',
        'alert.footer',
    ];

    /**
     * @param list<string> $channels the channels this copy is sent on
     * @param string $slackMessage the Slack message class to build (the Slack channel package's, by default)
     * @param array<string, string> $texts translated lines by message key; read from the translator when empty
     */
    public function __construct(
        public readonly AlertContext $context,
        public readonly array $channels,
        public readonly string $slackMessage = self::SLACK_MESSAGE,
        public readonly array $texts = [],
    ) {}

    /**
     * The alert's lines in the current locale, built up front so a queued alert serializes
     * strings rather than a translator.
     *
     * @return array<string, string>
     */
    public static function texts(Translator $translator): array
    {
        $texts = [];

        foreach (self::KEYS as $key) {
            $line = $translator->get(self::NAMESPACE . $key);
            $texts[$key] = is_string($line) ? $line : $key;
        }

        return $texts;
    }

    /** Whether laravel/slack-notification-channel is installed. */
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
        return new MailMessage()
            ->subject($this->context->headline())
            // Larastan cannot see the package's view namespace; PackageLayoutTest proves it exists.
            ->markdown(self::VIEW, [ // @phpstan-ignore argument.type
                'headline' => $this->context->headline(),
                'lines'    => $this->lines(),
                'footer'   => $this->text('alert.footer'),
            ]);
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

    /** The Slack message; only built when the Slack channel package is installed. */
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
            $this->text('alert.labels.event')   => $this->context->event,
            $this->text('alert.labels.store')   => $this->context->store ?? '-',
            $this->text('alert.labels.key')     => $this->context->key ?? '-',
            $this->text('alert.labels.scope')   => $this->context->scope ?? '-',
            $this->text('alert.labels.locale')  => $this->context->locale === null || $this->context->locale === '' ? '-' : $this->context->locale,
            $this->text('alert.labels.actor')   => $this->context->actor,
            $this->text('alert.labels.outcome') => $this->context->outcome,
            $this->text('alert.labels.at')      => $this->context->occurredAt->toIso8601String(),
        ];

        foreach ($this->context->details as $name => $value) {
            $lines[ucfirst(str_replace('_', ' ', $name))] = is_bool($value) ? $this->text($value ? 'alert.yes' : 'alert.no') : (string) ($value ?? '-');
        }

        return $lines;
    }

    private function text(string $key): string
    {
        $line = $this->texts[$key] ?? __(self::NAMESPACE . $key);

        return is_string($line) ? $line : $key;
    }
}
