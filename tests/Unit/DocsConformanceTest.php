<?php

declare(strict_types=1);

use Simtabi\Laranail\Package\Tools\Testing\AssertsDocsConformance;

uses(AssertsDocsConformance::class);

// Floor = the concern pages the standard requires (installation, getting-started,
// configuration, architecture, release). Raise it as pages are added.
it('conforms to the org docs standard', function (): void {
    $this->assertDocsConformToStandard(dirname(__DIR__, 2), 5);

    expect(true)->toBeTrue();
});
