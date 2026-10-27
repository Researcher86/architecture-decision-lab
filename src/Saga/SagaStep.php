<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Saga;

use Closure;

final readonly class SagaStep
{
    /**
     * @param Closure(): void $action
     * @param Closure(): void $compensation
     */
    public function __construct(
        public string $name,
        public Closure $action,
        public Closure $compensation,
    ) {
    }
}
