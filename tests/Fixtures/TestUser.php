<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/**
 * An unsaved user for access tests: authenticatable and authorizable, never touches a database.
 */
final class TestUser extends User
{
    protected $guarded = [];

    /** @var list<string>|null abilities of the "current token", as Sanctum would expose them */
    public ?array $tokenAbilities = null;

    public static function make(int $id = 1): self
    {
        $user = new self;
        $user->forceFill(['id' => $id]);

        return $user;
    }

    public function currentAccessToken(): ?object
    {
        return $this->tokenAbilities === null ? null : (object) ['abilities' => $this->tokenAbilities];
    }
}
