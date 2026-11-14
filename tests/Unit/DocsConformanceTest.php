<?php

declare(strict_types=1);

use Simtabi\Laranail\Package\Tools\Testing\AssertsDocsConformance;

uses(AssertsDocsConformance::class);

// Floor = the 53 pages shipped today (7 guides, 26 reference, 20 recipes; counted 2026-11-14). Raise it as pages
// are added; a glob that suddenly matches fewer has broken rather than found a clean tree.
it('conforms to the org docs standard', function (): void {
    $this->assertDocsConformToStandard(dirname(__DIR__, 2), 53);

    expect(true)->toBeTrue();
});
