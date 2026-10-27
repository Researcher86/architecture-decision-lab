<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

enum DeploymentShape: string
{
    case Monolith = 'monolith';
    case ModularMonolith = 'modular-monolith';
    case Microservices = 'microservices';

    public function isSingleProcess(): bool
    {
        return $this !== self::Microservices;
    }
}
