<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Diagnostics;

use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorCheck;
use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorResult;

/**
 * One finding of `laranail::settings.doctor`: a check that has already run, in the shape
 * package-tools' doctor reporter renders.
 */
final readonly class Finding implements DoctorCheck
{
    public function __construct(
        private string $name,
        private string $description,
        public DoctorResult $result,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function run(): DoctorResult
    {
        return $this->result;
    }
}
