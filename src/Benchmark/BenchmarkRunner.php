<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Benchmark;

use Closure;
use InvalidArgumentException;

/**
 * Runs a callable often enough, and often enough times, to say something about
 * it. Warm-up repetitions are discarded; the timed repetitions form a
 * distribution.
 */
final readonly class BenchmarkRunner
{
    public function __construct(
        private int $repetitions = 5,
        private int $warmups = 1,
    ) {
        if ($this->repetitions < 1) {
            throw new InvalidArgumentException('repetitions must be at least 1');
        }

        if ($this->warmups < 0) {
            throw new InvalidArgumentException('warmups cannot be negative');
        }
    }

    /**
     * @param Closure(): mixed $operation
     */
    public function run(string $name, Closure $operation, int $iterations = 1): BenchmarkResult
    {
        if ($iterations < 1) {
            throw new InvalidArgumentException(sprintf('%s: iterations must be at least 1', $name));
        }

        for ($warmup = 0; $warmup < $this->warmups; ++$warmup) {
            $this->measureOnce($operation, $iterations);
        }

        gc_collect_cycles();
        $before = $this->memory();
        $samples = [];

        for ($repetition = 0; $repetition < $this->repetitions; ++$repetition) {
            $samples[] = $this->measureOnce($operation, $iterations);
        }

        $after = $this->memory();

        /** @var non-empty-list<float> $samples */
        return new BenchmarkResult(
            name: $name,
            iterations: $iterations,
            repetitions: $this->repetitions,
            elapsedSeconds: array_sum($samples),
            timings: Timings::of($samples),
            phpDelta: $after['usage'] - $before['usage'],
            peakDelta: max(0, $after['peak'] - $before['peak']),
        );
    }

    /**
     * @param Closure(): mixed $operation
     */
    private function measureOnce(Closure $operation, int $iterations): float
    {
        $start = hrtime(true);

        for ($i = 0; $i < $iterations; ++$i) {
            $operation();
        }

        return (hrtime(true) - $start) / 1e9;
    }

    /**
     * @return array{usage: int, peak: int}
     */
    private function memory(): array
    {
        return [
            'usage' => memory_get_usage(false),
            'peak' => memory_get_peak_usage(false),
        ];
    }
}
