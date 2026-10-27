<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Foundation;

/**
 * A logical clock in milliseconds.
 *
 * Experiments use virtual time so a queue lag, an outbox retry or a 2PC hold
 * can be measured without making the test suite sleep. Wall-clock timings stay
 * in the benchmark harness; this clock answers "how much logical time passed".
 */
final class VirtualClock
{
    public function __construct(
        private int $now = 0,
    ) {
        if ($this->now < 0) {
            throw new \InvalidArgumentException('clock cannot start before 0');
        }
    }

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $milliseconds): void
    {
        if ($milliseconds < 0) {
            throw new \InvalidArgumentException('time cannot move backwards');
        }

        $this->now += $milliseconds;
    }

    public function set(int $milliseconds): void
    {
        if ($milliseconds < $this->now) {
            throw new \InvalidArgumentException('time cannot move backwards');
        }

        $this->now = $milliseconds;
    }
}
