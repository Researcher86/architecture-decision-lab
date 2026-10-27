<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

enum ConsistencyMode: string
{
    case Strong = 'strong';
    case Eventual = 'eventual';
}
