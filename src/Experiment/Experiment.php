<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Experiment;

use Closure;

/**
 * One runnable experiment: a name, a description, the options it reads, and a
 * closure that does the work. The descriptor is cheap because discovery loads
 * it without running anything.
 */
final readonly class Experiment
{
    public function __construct(
        public string $name,
        public string $description,
        /** @var list<string> */
        public array $supports,
        /** @var Closure(Options, Output): void */
        public Closure $run,
    ) {
    }
}
