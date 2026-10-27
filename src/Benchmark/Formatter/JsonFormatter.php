<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Benchmark\Formatter;

use ArchitectureDecisionLab\Benchmark\BenchmarkReport;

final readonly class JsonFormatter implements Formatter
{
    public function format(BenchmarkReport $report): string
    {
        $results = [];

        foreach ($report->results as $result) {
            $results[] = [
                'name' => $result->name,
                'iterations' => $result->iterations,
                'repetitions' => $result->repetitions,
                'elapsed_seconds' => $result->elapsedSeconds,
                'timings' => [
                    'min' => $result->timings->min,
                    'max' => $result->timings->max,
                    'mean' => $result->timings->mean,
                    'median' => $result->timings->median,
                    'p95' => $result->timings->p95,
                    'mad' => $result->timings->mad,
                ],
                'operations_per_second' => $result->operationsPerSecond(),
                'php_delta_bytes' => $result->phpDelta,
                'peak_delta_bytes' => $result->peakDelta,
            ];
        }

        return json_encode(
            [
                'suite' => $report->suite,
                'environment' => $report->environment->toArray(),
                'results' => $results,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
