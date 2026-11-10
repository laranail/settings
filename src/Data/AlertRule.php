<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * One entry of `laranail.settings.alerts.rules`: which events it answers (by short class name,
 * `SettingSaved`, or FQCN), narrowed by key globs (`mail.*`), stores, sensitive keys only and
 * outcomes (`allowed`, `denied`); and where it sends: mail addresses, a Slack webhook (when
 * laravel/slack-notification-channel is installed), and a database notifiable model. `queue`
 * names the queue to send on; null sends at once.
 */
final readonly class AlertRule
{
    /**
     * @param list<string> $events
     * @param list<string>|null $keys
     * @param list<string>|null $stores
     * @param list<string>|null $outcomes
     * @param list<string> $mail
     * @param array{type: class-string<Model>, id: int|string}|null $database
     */
    public function __construct(
        public array $events,
        public ?array $keys = null,
        public ?array $stores = null,
        public bool $sensitiveOnly = false,
        public ?array $outcomes = null,
        public array $mail = [],
        public ?string $slack = null,
        public ?array $database = null,
        public ?string $queue = null,
    ) {}

    /**
     * @param array<array-key, mixed> $rule
     *
     * @throws InvalidConfiguration naming the rule's config path
     */
    public static function fromConfig(array $rule, int|string $index): self
    {
        $path = 'laranail.settings.alerts.rules.' . $index;
        $notify = $rule['notify'] ?? [];

        if (! is_array($notify)) {
            throw new InvalidConfiguration($path . '.notify: an array of mail, slack and database.');
        }

        $events = self::strings($rule['events'] ?? null, $path . '.events', nullable: false) ?? [];

        if ($events === []) {
            throw new InvalidConfiguration($path . '.events: name at least one event.');
        }

        $sensitive = $rule['sensitive_only'] ?? false;
        $slack = $notify['slack'] ?? null;
        $queue = $rule['queue'] ?? null;

        if (! is_bool($sensitive)) {
            throw new InvalidConfiguration($path . '.sensitive_only: true or false.');
        }

        if ($slack !== null && (! is_string($slack) || $slack === '')) {
            throw new InvalidConfiguration($path . '.notify.slack: a webhook URL, or null.');
        }

        if ($queue !== null && (! is_string($queue) || $queue === '')) {
            throw new InvalidConfiguration($path . '.queue: a queue name, or null to send at once.');
        }

        return new self(
            $events,
            self::strings($rule['keys'] ?? null, $path . '.keys'),
            self::strings($rule['stores'] ?? null, $path . '.stores'),
            $sensitive,
            self::strings($rule['outcomes'] ?? null, $path . '.outcomes'),
            self::strings($notify['mail'] ?? [], $path . '.notify.mail', nullable: false) ?? [],
            $slack,
            self::database($notify['database'] ?? null, $path . '.notify.database'),
            $queue,
        );
    }

    /** Whether this rule answers the alert. */
    public function matches(object $event, AlertContext $context): bool
    {
        if (! array_any($this->events, static fn (string $name): bool => $name === $context->event || $name === $event::class)) {
            return false;
        }

        if ($this->stores !== null && ! in_array($context->store, $this->stores, true)) {
            return false;
        }

        if ($this->keys !== null && ($context->key === null || ! array_any($this->keys, static fn (string $glob): bool => Str::is($glob, $context->key)))) {
            return false;
        }

        if ($this->sensitiveOnly && ! $context->sensitive) {
            return false;
        }

        return $this->outcomes === null || in_array($context->outcome, $this->outcomes, true);
    }

    /** @return list<string>|null */
    private static function strings(mixed $value, string $path, bool $nullable = true): ?array
    {
        if ($value === null && $nullable) {
            return null;
        }

        if (! is_array($value) || ! array_all($value, static fn (mixed $item): bool => is_string($item) && $item !== '')) {
            throw new InvalidConfiguration($path . ': a list of strings' . ($nullable ? ', or null for any.' : '.'));
        }

        /** @var list<string> */
        return array_values($value);
    }

    /** @return array{type: class-string<Model>, id: int|string}|null */
    private static function database(mixed $value, string $path): ?array
    {
        if ($value === null) {
            return null;
        }

        $type = is_array($value) ? ($value['type'] ?? null) : null;
        $id = is_array($value) ? ($value['id'] ?? null) : null;

        if (! is_string($type) || ! is_subclass_of($type, Model::class) || (! is_int($id) && ! is_string($id))) {
            throw new InvalidConfiguration($path . ': [type => an Eloquent model class using Notifiable, id => its key], or null.');
        }

        return ['type' => $type, 'id' => $id];
    }
}
