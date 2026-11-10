<?php

declare(strict_types=1);

use Simtabi\Laranail\Package\Tools\Testing\AssertsDocsConformance;

uses(AssertsDocsConformance::class);

// Floor = the 21 pages shipped today (5 guides, 12 reference, 4 recipes). Raise it as pages
// are added; a glob that suddenly matches fewer has broken rather than found a clean tree.
it('conforms to the org docs standard', function (): void {
    $this->assertDocsConformToStandard(dirname(__DIR__, 2), 24);

    expect(true)->toBeTrue();
});
