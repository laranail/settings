<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * No layer of the store can accept the write.
 */
final class StoreNotWritable extends SettingsException {}
