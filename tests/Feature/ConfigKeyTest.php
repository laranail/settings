<?php

declare(strict_types=1);

/**
 * A bare `config('settings.*')` read returns null rather than failing, so a package can run on
 * its defaults for ever without anyone noticing. Scanned in the source, because the defect
 * lives in lines a test may never execute.
 */
it('reads configuration only at the registered laranail.settings key', function (): void {
    $this->assertReadsConfigAtRegisteredKey(dirname(__DIR__, 2) . '/src', 'settings');

    expect(true)->toBeTrue();
});
