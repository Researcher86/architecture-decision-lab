<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Instrumentation;

/**
 * A monotonic stopwatch based on hrtime().
 */
final class Stopwatch
{
    private ?int $startedAt = null;

    public function start(): void
    {
        $this->startedAt = hrtime(true);
    }

    public function stop(): float
    {
        if ($this->startedAt === null) {
            throw new \LogicException('stopwatch was not started');
        }

        $elapsed = (hrtime(true) - $this->startedAt) / 1e9;
        $this->startedAt = null;

        return $elapsed;
    }

    public function isRunning(): bool
    {
        return $this->startedAt !== null;
    }
}
