<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Integration;

/**
 * The result of one scenario run. `invariants` is not a claim that the system
 * is correct in general; it is the list of things this run was checked for.
 */
final readonly class ScenarioReport
{
    /**
     * @param list<string> $timeline
     * @param array<string, int|string> $summary
     * @param list<string> $invariants
     * @param array<string, int> $counters
     */
    public function __construct(
        public string $mode,
        public LabConfig $config,
        public array $timeline,
        public array $summary,
        public array $invariants,
        public bool $passed,
        public array $counters,
    ) {
    }
}
