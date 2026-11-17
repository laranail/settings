<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

/**
 * The `LARANAIL_SETTINGS_*` environment variables, each with the configurator method that replaces
 * it. Read from the process environment and `$_SERVER`/`$_ENV` directly, never through `env()`,
 * which returns null once config is cached, so a variable still set is reported either way.
 */
final readonly class LeftoverEnvironment
{
    /** @var array<string, string> variable => the setup method that replaces it */
    public const array REPLACEMENTS = [
        'LARANAIL_SETTINGS_STORE'                   => 'defaultStore()',
        'LARANAIL_SETTINGS_BOOTSTRAP'               => 'bootstrap()',
        'LARANAIL_SETTINGS_PROMOTE'                 => 'promote()',
        'LARANAIL_SETTINGS_TABLE_PREFIX'            => 'tables(prefix:)',
        'LARANAIL_SETTINGS_TENANCY'                 => 'tenancy()',
        'LARANAIL_SETTINGS_SEED'                    => 'seed()->enabled()',
        'LARANAIL_SETTINGS_MAX_VALUE_BYTES'         => 'values()->maxBytes()',
        'LARANAIL_SETTINGS_CACHE'                   => 'cache()->enabled()',
        'LARANAIL_SETTINGS_CACHE_STORE'             => 'cache()->store()',
        'LARANAIL_SETTINGS_DB_CONNECTION'           => "layer('database')->connection()",
        'LARANAIL_SETTINGS_RUNTIME_DISK'            => "layer('runtime')->disk()",
        'LARANAIL_SETTINGS_ACTOR_RESOLVER'          => 'resolveActorsUsing()',
        'LARANAIL_SETTINGS_AUTHORIZER'              => 'authorizeUsing()',
        'LARANAIL_SETTINGS_REQUIRE_ACTOR'           => 'requireActor()',
        'LARANAIL_SETTINGS_VERIFIER'                => 'verifyUsing()',
        'LARANAIL_SETTINGS_LOG_LEVEL'               => 'logging()->level()',
        'LARANAIL_SETTINGS_ALERTS'                  => 'alerts()->enabled()',
        'LARANAIL_SETTINGS_PERSISTED_CONFIGURATION' => 'persistedConfiguration()',
        'LARANAIL_SETTINGS_HTTP'                    => 'http()->enabled()',
        'LARANAIL_SETTINGS_HTTP_PREFIX'             => 'http()->prefix()',
    ];

    /** @return array<string, string> each variable still set => the setup method that replaces it */
    public static function find(): array
    {
        return array_filter(self::REPLACEMENTS, static fn (string $method, string $variable): bool => getenv($variable) !== false
            || array_key_exists($variable, $_SERVER)
            || array_key_exists($variable, $_ENV), ARRAY_FILTER_USE_BOTH);
    }
}
