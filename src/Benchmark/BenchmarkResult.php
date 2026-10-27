<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Benchmark;

/**
 * One benchmark, measured. `phpDelta` is the Zend allocator delta, not RSS.
 */
final readonly class BenchmarkResult
{
    public function __construct(
        public string $name,
        public int $iterations,
        public int $repetitions,
        public float $elapsedSeconds,
        public Timings $timings,
        public int $phpDelta,
        public int $peakDelta,
    ) {
    }

    public function operationsPerSecond(): float
    {
        return $this->timings->median <= 0.0 ? 0.0 : $this->iterations / $this->timings->median;
    }

    public function secondsPerOperation(): float
    {
        return $this->timings->median / $this->iterations;
    }
}
