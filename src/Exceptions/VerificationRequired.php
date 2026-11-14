<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;

/** The actor must verify their identity again before this ability. */
final class VerificationRequired extends SettingsException
{
    public const int STATUS = 428;

    public const string TYPE = 'verification-required';

    public function __construct(
        public readonly Ability $ability,
        public readonly string $store,
        public readonly ?string $key,
    ) {
        parent::__construct(sprintf(
            'Verification required: [%s] on [%s]%s needs a recent identity check.',
            $ability->value,
            $store,
            $key === null ? '' : ' key [' . $key . ']',
        ));
    }

    public static function for(Ability $ability, Subject $subject): self
    {
        return new self($ability, $subject->store->value, $subject->key?->value);
    }
}
