<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

use Psr\Log\LogLevel;

/** `logging.*`, inside `SettingsSetup::logging()`. */
final readonly class LoggingSetup
{
    private const array LEVELS = [LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];

    public function __construct(private SetupValues $values) {}

    /** `logging.level`: the least severe operational message written. */
    public function level(string $level): self
    {
        $this->values->set('logging.level', SetupValues::oneOf('logging()->level()', 'logging.level', $level, self::LEVELS));

        return $this;
    }

    /** `logging.channel`: a log channel (null: the application's default). */
    public function channel(?string $channel): self
    {
        $this->values->set('logging.channel', $channel === null ? null : SetupValues::filled('logging()->channel()', 'logging.channel', $channel));

        return $this;
    }
}
