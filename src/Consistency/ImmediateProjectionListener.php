<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Consistency;

final readonly class ImmediateProjectionListener implements EventListener
{
    public function __construct(
        private IdempotentProjector $projector,
    ) {
    }

    public function handle(EventEnvelope $event): void
    {
        $this->projector->handle($event);
    }
}
