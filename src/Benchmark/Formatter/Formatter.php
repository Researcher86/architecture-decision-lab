<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Benchmark\Formatter;

use ArchitectureDecisionLab\Benchmark\BenchmarkReport;

interface Formatter
{
    public function format(BenchmarkReport $report): string;
}
