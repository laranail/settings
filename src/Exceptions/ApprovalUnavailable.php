<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/**
 * The write needs a second person's approval, and this store cannot hold change requests. In
 * this release no store can: approvals need the database layer, so every write to a setting
 * marked `requiresApproval()` raises this, whoever makes it.
 */
final class ApprovalUnavailable extends SettingsException {}
