<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Benchmark;

use Closure;

/**
 * One named operation to measure, with the iteration count that makes it
 * measurable.
 */
final readonly class Benchmark
{
    public function __construct(
        public string $name,
        public int $iterations,
        /** @var Closure(): mixed */
        public Closure $run,
    ) {
    }
}
