<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

enum DatabaseMode: string
{
    case Shared = 'shared';
    case PerService = 'per-service';
}
