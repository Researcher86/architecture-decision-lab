<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Consistency;

interface EventListener
{
    public function handle(EventEnvelope $event): void;
}
