<?php

declare(strict_types=1);

use Simtabi\Laranail\Package\Tools\Testing\AssertsDocsConformance;

uses(AssertsDocsConformance::class);

// Floor = the 41 pages shipped today (5 guides, 25 reference, 11 recipes; counted 2026-11-10). Raise it as pages
// are added; a glob that suddenly matches fewer has broken rather than found a clean tree.
it('conforms to the org docs standard', function (): void {
    $this->assertDocsConformToStandard(dirname(__DIR__, 2), 41);

    expect(true)->toBeTrue();
});
