<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

/** `tenancy.*`, inside `SettingsSetup::tenancy()`. */
final readonly class TenancySetup
{
    public function __construct(private SetupValues $values) {}

    /** One set of settings for the application. */
    public function none(): self
    {
        $this->values->set('tenancy.mode', 'none');

        return $this;
    }

    /** A `tenant` column on every table, filled and filtered with the current tenant. */
    public function column(): self
    {
        $this->values->set('tenancy.mode', 'column');

        return $this;
    }

    /** A database per tenant. */
    public function database(): self
    {
        $this->values->set('tenancy.mode', 'database');

        return $this;
    }

    /** `tenancy.discriminator`: auto, null, stancl, spatie, or a TenantDiscriminator class. */
    public function discriminator(string $discriminator): self
    {
        $this->values->set('tenancy.discriminator', SetupValues::filled('tenancy()->discriminator()', 'tenancy.discriminator', $discriminator));

        return $this;
    }
}
