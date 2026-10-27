<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Integration;

/**
 * Input for the one end-to-end scenario. The mode changes only the failure the
 * run is asked to survive; the seven decisions stay fixed.
 */
final readonly class ScenarioOptions
{
    public function __construct(
        public string $mode = 'compensate',
        public string $seed = 'trip-booking',
        public string $databaseDirectory = 'var/databases',
        public int $projectionLagMs = 50,
        public int $remoteLatencyMs = 2,
    ) {
        if (!in_array($this->mode, ['success', 'compensate'], true)) {
            throw new \InvalidArgumentException('mode must be success or compensate');
        }

        if ($this->projectionLagMs < 0) {
            throw new \InvalidArgumentException('projection lag cannot be negative');
        }

        if ($this->remoteLatencyMs < 0) {
            throw new \InvalidArgumentException('remote latency cannot be negative');
        }
    }
}
