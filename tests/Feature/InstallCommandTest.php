<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

// Writes into the testbench application shared by every process (config/, database/), so it
// cannot run beside itself in parallel; the parallel mutation run leaves this group out.
pest()->group('shared-app');

beforeEach(function (): void {
    File::delete(config_path('laranail/settings.php'));
});

afterEach(function (): void {
    File::delete(config_path('laranail/settings.php'));
});

it('publishes the config file to config/laranail/settings.php', function (): void {
    expect(config_path('laranail/settings.php'))->not->toBeFile();

    $this->artisan('laranail::settings.install')->assertSuccessful();

    expect(config_path('laranail/settings.php'))->toBeFile()
        ->and(File::get(config_path('laranail/settings.php')))
        ->toBe(File::get(dirname(__DIR__, 2) . '/config/settings.php'));
});
