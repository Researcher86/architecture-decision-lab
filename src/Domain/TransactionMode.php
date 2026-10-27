<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

enum TransactionMode: string
{
    case Local = 'local';
    case None = 'none';
    case Saga = 'saga';
    case TwoPhaseCommit = 'two-phase-commit';
}
