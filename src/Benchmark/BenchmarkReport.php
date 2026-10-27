<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Benchmark;

/**
 * A set of results plus the machine they came from.
 */
final readonly class BenchmarkReport
{
    public function __construct(
        public string $suite,
        public Environment $environment,
        /** @var list<BenchmarkResult> */
        public array $results,
    ) {
    }
}
