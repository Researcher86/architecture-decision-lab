<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Experiment;

/**
 * A finished experiment run: which options were used and what was measured.
 */
final readonly class ExperimentResult
{
    public function __construct(
        public string $name,
        public string $description,
        /** @var array<string, int|float|string|bool> */
        public array $options,
        /** @var array<string, int|float|string|bool|null> */
        public array $measurements,
        public float $elapsedSeconds,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'experiment' => $this->name,
            'description' => $this->description,
            'options' => (object) $this->options,
            'measurements' => (object) $this->measurements,
            'elapsed_seconds' => $this->elapsedSeconds,
        ];
    }
}
