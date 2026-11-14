<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * Which runtime-file values a promotion copies: the application-wide (global) values
 * (`settings`), per-owner values (`owners`), optionally only for some owner aliases.
 *
 * The runtime file holds setting values only, so these are the only two kinds it can promote.
 */
final readonly class PromotionSelection
{
    /** The kinds a runtime file can hold. */
    public const array KINDS = ['settings', 'owners'];

    /** @param list<string>|null $ownerAliases owner values only for these aliases; null for every alias */
    public function __construct(
        public bool $settings = true,
        public bool $owners = true,
        public ?array $ownerAliases = null,
    ) {}

    /**
     * Build a selection from configuration or console options.
     *
     * @param mixed $include `['*']`, or a list of `settings` and `owners`
     * @param mixed $owners null, or a list of owner aliases
     *
     * @throws InvalidConfiguration naming `$path`
     */
    public static function from(mixed $include, mixed $owners, string $path): self
    {
        $kinds = is_array($include) ? array_values(array_filter($include, is_string(...))) : null;

        if ($kinds === null || $kinds === [] || count($kinds) !== count($include)) {
            throw new InvalidConfiguration($path . '.include: a list of settings and owners, or [*].');
        }

        $unknown = array_diff($kinds, ['*', ...self::KINDS]);

        if ($unknown !== []) {
            throw new InvalidConfiguration(sprintf('%s.include: [%s] cannot be promoted; the runtime file holds setting values only (settings, owners).', $path, implode(', ', $unknown)));
        }

        $aliases = is_array($owners) ? array_values(array_filter($owners, is_string(...))) : null;

        if ($owners !== null && ($aliases === null || $aliases === [] || count($aliases) !== count($owners))) {
            throw new InvalidConfiguration($path . '.owners: a list of owner aliases, or null for every owner.');
        }

        $all = in_array('*', $kinds, true);

        return new self($all || in_array('settings', $kinds, true), $all || in_array('owners', $kinds, true), $aliases);
    }

    /** @return array{include: list<string>, owners: list<string>|null} the selection as `promote` options */
    public function toOptions(): array
    {
        $include = $this->settings && $this->owners ? ['*'] : array_keys(array_filter(['settings' => $this->settings, 'owners' => $this->owners]));

        return ['include' => $include, 'owners' => $this->ownerAliases];
    }

    /** Whether a runtime-file record is promoted by this selection. */
    public function includes(Record $record): bool
    {
        $owner = $record->scope->owner();

        if (! $owner instanceof OwnerRef) {
            return $this->settings;
        }

        return $this->owners && ($this->ownerAliases === null || in_array($owner->alias, $this->ownerAliases, true));
    }

    /** Whether every record of the file is promoted. */
    public function isEverything(): bool
    {
        return $this->settings && $this->owners && $this->ownerAliases === null;
    }
}
