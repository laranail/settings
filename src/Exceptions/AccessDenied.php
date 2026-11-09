<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;

/**
 * The actor may not perform this ability on this subject. Carries the ability, store and key so
 * a caller can report it, and never a value.
 */
final class AccessDenied extends SettingsException
{
    public function __construct(
        public readonly Ability $ability,
        public readonly string $store,
        public readonly ?string $key,
        public readonly ?string $reason = null,
    ) {
        parent::__construct(sprintf(
            'Access denied: [%s] on [%s]%s%s.',
            $ability->value,
            $store,
            $key === null ? '' : ' key [' . $key . ']',
            $reason === null ? '' : ' (' . $reason . ')',
        ));
    }

    public static function for(Ability $ability, Subject $subject, ?string $reason = null): self
    {
        return new self($ability, $subject->store->value, $subject->key?->value, $reason);
    }
}
