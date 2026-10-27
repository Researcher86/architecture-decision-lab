<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Benchmark\Formatter;

use ArchitectureDecisionLab\Benchmark\BenchmarkReport;
use ArchitectureDecisionLab\Foundation\ByteFormatter;

final readonly class TextFormatter implements Formatter
{
    public function format(BenchmarkReport $report): string
    {
        $environment = $report->environment;
        $text = sprintf("Benchmark suite: %s\n", $report->suite);
        $text .= sprintf(
            "PHP %s on %s %s, %s x %d, memory_limit %s, cgroup %s\n",
            $environment->phpVersion,
            $environment->os,
            $environment->kernel,
            $environment->architecture,
            $environment->cpuCount,
            $environment->memoryLimit,
            $environment->cgroupMemoryLimit === null ? 'unlimited' : $environment->memoryLimit,
        );
        $text .= sprintf(
            "OPcache %s, JIT %s, Xdebug %s, allocator %s\n",
            $environment->opcacheEnabled ? 'on' : 'off',
            $environment->jitEnabled ? 'on' : 'off',
            $environment->xdebugMode,
            $environment->allocator,
        );
        $text .= sprintf("git %s\n\n", $environment->commit);
        $text .= sprintf(
            "%-28s %10s %12s %12s %12s %14s %12s\n",
            'benchmark',
            'iterations',
            'median',
            'p95',
            'max',
            'ops/sec',
            'php delta',
        );
        $text .= str_repeat('-', 106) . "\n";

        foreach ($report->results as $result) {
            $text .= sprintf(
                "%-28s %10d %12s %12s %12s %14s %12s\n",
                $result->name,
                $result->iterations,
                $this->seconds($result->timings->median),
                $this->seconds($result->timings->p95),
                $this->seconds($result->timings->max),
                number_format($result->operationsPerSecond()),
                ByteFormatter::format($result->phpDelta),
            );
        }

        return $text;
    }

    private function seconds(float $seconds): string
    {
        return $seconds < 0.001
            ? sprintf('%.1f us', $seconds * 1e6)
            : sprintf('%.3f ms', $seconds * 1e3);
    }
}
