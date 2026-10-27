<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Consistency;

use ArchitectureDecisionLab\Foundation\VirtualClock;
use ArchitectureDecisionLab\Instrumentation\Counters;

/**
 * Advances a lagging projection. `drain()` moves logical time to the next due
 * event until the queue is empty, which is what "converged" means for the
 * experiment.
 */
final readonly class ProjectionRunner
{
    public function __construct(
        private ProjectionQueue $queue,
        private IdempotentProjector $projector,
        private VirtualClock $clock,
        private Counters $counters,
    ) {
    }

    public function advance(): int
    {
        $applied = 0;

        foreach ($this->queue->due($this->clock->now()) as $event) {
            $this->projector->handle($event);
            ++$applied;
        }

        $this->counters->increment('projectionAdvanceCalls');
        $this->counters->add('projectionDrained', $applied);

        return $applied;
    }

    public function drain(): int
    {
        $applied = 0;

        while (!$this->queue->isEmpty()) {
            $next = $this->queue->next();

            if ($next === null) {
                break;
            }

            $this->clock->set($next['due_at']);
            $this->projector->handle($next['event']);
            $this->queue->removeNext();
            ++$applied;
        }

        $this->counters->increment('projectionDrainCalls');
        $this->counters->add('projectionDrained', $applied);

        return $applied;
    }

    public function pending(): int
    {
        return $this->queue->size();
    }
}
