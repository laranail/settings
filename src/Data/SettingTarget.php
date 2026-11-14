<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Stores\Store;

/** The target of a write: store, key, owner, locale, definition and reason. */
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

    /** The subject abilities are checked against; a key in `approvals.keys` needs approval. */
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
