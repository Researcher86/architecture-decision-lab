<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Consistency;

use ArchitectureDecisionLab\Foundation\VirtualClock;

final readonly class QueuedProjectionListener implements EventListener
{
    public function __construct(
        private ProjectionQueue $queue,
        private VirtualClock $clock,
        private int $lagMs,
    ) {
    }

    public function handle(EventEnvelope $event): void
    {
        $this->queue->enqueue($event, $this->clock->now() + $this->lagMs);
    }
}
