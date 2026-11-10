<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Typed;

use DateTimeImmutable;
use Simtabi\Laranail\Settings\Typed\Attributes\Key;
use Simtabi\Laranail\Settings\Typed\TypedSettings;
use Simtabi\Laranail\Settings\Typed\Attributes\Group;
use Simtabi\Laranail\Settings\Typed\Attributes\Store;
use Simtabi\Laranail\Settings\Typed\Attributes\Encrypted;

#[Store('settings')]
#[Group('mail')]
final class MailSettings extends TypedSettings
{
    public string $fromAddress = 'hello@example.test';

    public int $port = 25;

    public float $timeout = 1.5;

    public bool $secure = false;

    /** @var list<string> */
    public array $cc = [];

    public Plan $plan = Plan::Free;

    public ?DateTimeImmutable $launchedAt = null;

    #[Encrypted]
    public ?string $password = null;

    #[Key('site.name')]
    public string $siteName = 'Acme';
}
