<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Processing;

use ArchitectureDecisionLab\Instrumentation\Counters;

/**
 * A bounded FIFO queue. "Bounded" is the point: an asynchronous edge without
 * back-pressure just moves the failure from the caller to memory.
 */
final class JobQueue
{
    /** @var list<BookingJob> */
    private array $jobs = [];

    public function __construct(
        private readonly int $limit,
        private readonly Counters $counters,
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException('queue limit must be at least 1');
        }
    }

    public function enqueue(BookingJob $job): bool
    {
        if (count($this->jobs) >= $this->limit) {
            $this->counters->increment('queueRejections');
            $this->counters->set('queueDepthGauge', count($this->jobs));

            return false;
        }

        $this->jobs[] = $job;
        $this->counters->increment('jobsEnqueued');
        $this->counters->set('queueDepthGauge', count($this->jobs));

        return true;
    }

    public function pop(): ?BookingJob
    {
        $job = array_shift($this->jobs);
        $this->counters->set('queueDepthGauge', count($this->jobs));

        return $job;
    }

    public function size(): int
    {
        return count($this->jobs);
    }

    public function isFull(): bool
    {
        return count($this->jobs) >= $this->limit;
    }

    public function limit(): int
    {
        return $this->limit;
    }
}
