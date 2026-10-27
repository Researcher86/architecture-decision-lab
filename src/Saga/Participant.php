<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Saga;

use Closure;

final readonly class Participant
{
    /**
     * @param Closure(): void $prepare
     * @param Closure(): void $commit
     * @param Closure(): void $abort
     */
    public function __construct(
        public string $name,
        public Closure $prepare,
        public Closure $commit,
        public Closure $abort,
    ) {
    }
}
