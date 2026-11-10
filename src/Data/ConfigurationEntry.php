<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * A persisted store or layer entry (§16.11): its kind, name and configuration, exactly as the
 * configuration file would hold it under `stores.<name>` or `layers.<name>`.
 */
final readonly class ConfigurationEntry
{
    public const string STORE = 'store';

    public const string LAYER = 'layer';

    /** @param array<array-key, mixed> $config */
    public function __construct(
        public string $kind,
        public string $name,
        public array $config = [],
    ) {
        if (! in_array($kind, [self::STORE, self::LAYER], true)) {
            throw new InvalidConfiguration(sprintf('A persisted configuration entry is a [store] or a [layer], not [%s].', $kind));
        }
    }
}
