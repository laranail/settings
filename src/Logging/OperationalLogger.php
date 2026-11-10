<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Logging;

use Stringable;
use Psr\Log\LogLevel;
use Psr\Log\LoggerTrait;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Configuration\RuntimeConfiguration;

/**
 * The package's operational log: denials, verification failures and configuration changes,
 * written to `logging.channel` (or a logger set at runtime with `logTo()`) at or above
 * `logging.level`. Messages carry store, key, scope and actor identifiers, never a value.
 */
final readonly class OperationalLogger implements LoggerInterface
{
    use LoggerTrait;

    private const array LEVELS = [
        LogLevel::DEBUG     => 0,
        LogLevel::INFO      => 1,
        LogLevel::NOTICE    => 2,
        LogLevel::WARNING   => 3,
        LogLevel::ERROR     => 4,
        LogLevel::CRITICAL  => 5,
        LogLevel::ALERT     => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private int $threshold;

    public function __construct(
        private LoggerInterface $logger,
        string $level = LogLevel::INFO,
        private ?RuntimeConfiguration $runtime = null,
    ) {
        $this->threshold = self::LEVELS[$level] ?? throw new InvalidConfiguration(sprintf(
            'laranail.settings.logging.level: unknown level [%s]; use one of %s.',
            $level,
            implode(', ', array_keys(self::LEVELS)),
        ));
    }

    /** @param array<array-key, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $rank = is_string($level) ? self::LEVELS[$level] ?? null : null;

        if ($rank === null || $rank < $this->threshold) {
            return;
        }

        ($this->runtime?->logger() ?? $this->logger)->log($level, $message, $context);
    }
}
