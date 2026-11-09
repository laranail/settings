<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Testing;

/**
 * The writes a {@see SettingsFake} and its derived handles recorded.
 */
final class FakeLog
{
    /** @var list<array{string, mixed}> */
    public array $saved = [];

    /** @var list<string> */
    public array $forgotten = [];
}
