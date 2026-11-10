<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Stores\Store;

/**
 * The input of every write action: one key in one resolved store, for one owner (or global)
 * and locale, with its definition and the reason given for the change.
 */
final readonly class SettingTarget
{
    public function __construct(
        public Store $store,
        public Key $key,
        public Locale $locale,
        public ?OwnerRef $owner = null,
        public ?Definition $definition = null,
        public ?string $reason = null,
    ) {}

    /**
     * The subject abilities are checked against. A key matched by the store's `approvals.keys`
     * needs approval as if its definition said so (§16.7).
     */
    public function subject(): Subject
    {
        $subject = Subject::of($this->store->name, $this->key, $this->locale, $this->owner, $this->definition);

        return $subject->requiresApproval || ! $this->store->requiresApproval($this->key) ? $subject : new Subject(
            $subject->store,
            $subject->key,
            $subject->owner,
            $subject->locale,
            $subject->category,
            $subject->sensitive,
            $subject->requiresVerification,
            requiresApproval: true,
        );
    }
}
