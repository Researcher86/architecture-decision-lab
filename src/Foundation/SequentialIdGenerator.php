<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Foundation;

/**
 * Predictable identifiers within one run.
 *
 * The database is the source of truth for data, but ids must not depend on a
 * random generator or on insertion timing: the book quotes exact rows.
 */
final class SequentialIdGenerator
{
    /** @var array<string, int> */
    private array $next = [];

    public function next(string $prefix): string
    {
        $this->next[$prefix] = ($this->next[$prefix] ?? 0) + 1;

        return sprintf('%s-%04d', $prefix, $this->next[$prefix]);
    }
}
