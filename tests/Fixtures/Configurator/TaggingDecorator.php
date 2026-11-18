<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Configurator;

use Override;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Configurator\SettingsDecorator;

/** Records its tag on every read, so a test can see which decorators a call went through, in order. */
final class TaggingDecorator extends SettingsDecorator
{
    /** @var list<string> */
    public static array $calls = [];

    public function __construct(Settings $inner, public readonly string $tag)
    {
        parent::__construct($inner);
    }

    #[Override]
    public function get(string $key, mixed $default = null): mixed
    {
        self::$calls[] = $this->tag;

        return parent::get($key, $default);
    }
}
