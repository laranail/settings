<?php

declare(strict_types=1);

use Simtabi\Laranail\Package\Tools\Testing\AssertsNoLiteralCredentials;

uses(AssertsNoLiteralCredentials::class);

// The floor is the number of PHP files under tests/ today. Raise it as the suite grows; a
// scan that suddenly inspects fewer files has broken rather than found a clean tree.
const SETTINGS_TEST_FILE_FLOOR = 9;

it('keeps literal credentials out of the tests', function (): void {
    $this->assertNoLiteralCredentialsIn([dirname(__DIR__)], minFiles: SETTINGS_TEST_FILE_FLOOR);

    expect(true)->toBeTrue();
});

it('names every generated credential with a single word', function (): void {
    $this->assertCredentialPurposesAreWordsIn([dirname(__DIR__)], minFiles: SETTINGS_TEST_FILE_FLOOR);

    expect(true)->toBeTrue();
});
