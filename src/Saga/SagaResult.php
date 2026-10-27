<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Saga;

final readonly class SagaResult
{
    /**
     * @param list<string> $completed
     */
    public function __construct(
        public bool $success,
        public string $failedStep,
        public array $completed,
        public int $compensationAttempts,
        public int $compensationFailures,
        public string $reason = '',
    ) {
    }
}
