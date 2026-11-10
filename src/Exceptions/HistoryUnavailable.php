<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * The store keeps no history: it has no active database layer, or the recorded schema has no
 * history table (`history.enabled` was off when it was migrated), or the version asked for is
 * not in it.
 */
final class HistoryUnavailable extends SettingsException {}
