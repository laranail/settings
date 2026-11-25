<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

// Writes into the testbench application shared by every process, so it cannot run in parallel.
// It also carries `mutation-excluded`, because Pest's parallel runner honours one --exclude-group.
pest()->group('shared-app', 'mutation-excluded');

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
