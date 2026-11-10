<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Authorization;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\Decision;
use Simtabi\Laranail\Settings\Enums\Ability;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Simtabi\Laranail\Settings\Contracts\Authorizer;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * Maps each ability to a permission name and asks the user's `can()`. Names may use
 * `{ability}`, `{store}` and `{key}`; an unmapped ability abstains.
 */
final readonly class PermissionsAuthorizer implements Authorizer
{
    /** @var array<string, string> */
    private array $permissions;

    /** @param array<array-key, mixed> $permissions ability value => permission name */
    public function __construct(array $permissions)
    {
        $map = [];

        foreach ($permissions as $ability => $permission) {
            if (Ability::tryFrom((string) $ability) === null || ! is_string($permission) || $permission === '') {
                throw new InvalidConfiguration(sprintf('laranail.settings.authorization.permissions.%s: map a known ability to a permission name.', $ability));
            }

            $map[(string) $ability] = $permission;
        }

        $this->permissions = $map;
    }

    public function authorize(Actor $actor, Ability $ability, Subject $subject): Decision
    {
        $template = $this->permissions[$ability->value] ?? null;

        if ($template === null) {
            return Decision::abstain(sprintf('no permission is mapped to [%s]', $ability->value));
        }

        if (! $actor->user instanceof Authorizable) {
            return Decision::deny('the actor cannot hold permissions');
        }

        $permission = strtr($template, [
            '{ability}' => $ability->value,
            '{store}'   => $subject->store->value,
            '{key}'     => $subject->key->value ?? '',
        ]);

        return $actor->user->can($permission, [$subject])
            ? Decision::allow()
            : Decision::deny(sprintf('permission [%s] is missing', $permission));
    }
}
