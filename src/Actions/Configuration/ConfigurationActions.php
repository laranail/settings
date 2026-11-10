<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Configuration;

/** The persisted configuration actions (§16.11), held together for entry points. */
final readonly class ConfigurationActions
{
    public function __construct(
        public SaveConfigurationEntry $save,
        public DeleteConfigurationEntry $delete,
        public ListConfigurationEntries $list,
        public SaveDefinition $saveDefinition,
        public DeleteDefinition $deleteDefinition,
    ) {}
}
