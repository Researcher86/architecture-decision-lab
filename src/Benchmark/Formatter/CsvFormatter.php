<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Benchmark\Formatter;

use ArchitectureDecisionLab\Benchmark\BenchmarkReport;

final readonly class CsvFormatter implements Formatter
{
    public function format(BenchmarkReport $report): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('cannot open temporary stream');
        }

        fputcsv($handle, [
            'suite', 'name', 'iterations', 'repetitions', 'median_seconds', 'p95_seconds',
            'min_seconds', 'max_seconds', 'mad_seconds', 'ops_per_second',
            'php_delta_bytes', 'peak_delta_bytes', 'commit',
        ]);

        foreach ($report->results as $result) {
            fputcsv($handle, [
                $report->suite,
                $result->name,
                $result->iterations,
                $result->repetitions,
                $result->timings->median,
                $result->timings->p95,
                $result->timings->min,
                $result->timings->max,
                $result->timings->mad,
                $result->operationsPerSecond(),
                $result->phpDelta,
                $result->peakDelta,
                $report->environment->commit,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);

        if ($csv === false) {
            throw new \RuntimeException('cannot read temporary stream');
        }

        fclose($handle);

        return $csv;
    }
}
