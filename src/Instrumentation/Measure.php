<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Instrumentation;

/**
 * One object that owns the counters, the stopwatch and the memory baseline of
 * an experiment. Experiments keep the three instruments separate in their
 * output; this facade only prevents each one from reinventing the wiring.
 */
final class Measure
{
    public readonly Counters $counters;
    public readonly MemoryMeter $memory;
    private readonly Stopwatch $stopwatch;
    /** @var array{usage: int, real: int, peak: int, real_peak: int} */
    private array $started;

    public function __construct()
    {
        $this->counters = new Counters();
        $this->memory = new MemoryMeter();
        $this->stopwatch = new Stopwatch();
        $this->started = $this->memory->snapshot();
    }

    public function start(): void
    {
        $this->counters->reset();
        $this->memory->resetPeak();
        $this->started = $this->memory->snapshot();
        $this->stopwatch->start();
    }

    /**
     * @return array{seconds: float, memory: array<string, int>, counters: array<string, int>}
     */
    public function stop(): array
    {
        $seconds = $this->stopwatch->stop();
        $after = $this->memory->snapshot();

        return [
            'seconds' => $seconds,
            'memory' => [
                'usage_delta' => $after['usage'] - $this->started['usage'],
                'peak_delta' => max(0, $after['peak'] - $this->started['peak']),
            ],
            'counters' => $this->counters->toArray(),
        ];
    }
}
