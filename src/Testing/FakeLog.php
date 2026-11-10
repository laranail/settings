<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Testing;

use Simtabi\Laranail\Settings\Audit\InMemoryAuditSink;

/** The writes and audit entries a {@see SettingsFake} and its handles recorded. */
final class FakeLog
{
    /** @var list<array{string, mixed}> */
    public array $saved = [];

    /** @var list<string> */
    public array $forgotten = [];

    public function __construct(
        public readonly InMemoryAuditSink $audit = new InMemoryAuditSink,
    ) {}
}
