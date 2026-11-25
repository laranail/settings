<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Enums\TenancyMode;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Tenancy\NullTenantDiscriminator;
use Simtabi\Laranail\Settings\Tenancy\ClosureTenantDiscriminator;

it('reports no tenant by default', function (): void {
    expect(new NullTenantDiscriminator()->current())->toBeNull();
});

it('turns a tenant key into a string', function (mixed $key, ?string $expected): void {
    expect(new ClosureTenantDiscriminator(static fn (): mixed => $key)->current())->toBe($expected);
})->with([
    'none'       => [null, null],
    'string'     => ['acme', 'acme'],
    'integer'    => [42, '42'],
    'stringable' => [new class implements Stringable
    {
        public function __toString(): string
        {
            return 'globex';
        }
    }, 'globex'],
    'longest' => [str_repeat('a', 64), str_repeat('a', 64)],
]);

it('refuses a key two tenants could share', function (mixed $key, string $message): void {
    expect(fn (): ?string => new ClosureTenantDiscriminator(static fn (): mixed => $key)->current())->toThrow(InvalidConfiguration::class, $message);
})->with([
    'array'    => [['acme'], 'returned a array'],
    'float'    => [1.5, 'returned a float'],
    'empty'    => ['', '1-64 characters'],
    'too long' => [str_repeat('a', 65), '1-64 characters'],
]);

it('knows which mode carries a tenant column', function (): void {
    expect(TenancyMode::Column->hasColumn())->toBeTrue()
        ->and(TenancyMode::None->hasColumn())->toBeFalse()
        ->and(TenancyMode::Database->hasColumn())->toBeFalse();
});
