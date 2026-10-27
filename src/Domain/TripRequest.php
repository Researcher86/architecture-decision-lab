<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

/**
 * A package request. A trip always has one flight, one hotel and one car; the
 * experiments vary how the package is composed, not what a trip means.
 */
final readonly class TripRequest
{
    public function __construct(
        public string $travelerId,
        public string $flightId,
        public string $hotelId,
        public string $carId,
        public int $units = 1,
    ) {
        if ($this->travelerId === '') {
            throw new \InvalidArgumentException('travelerId cannot be empty');
        }

        if ($this->units < 1) {
            throw new \InvalidArgumentException('units must be at least 1');
        }
    }
}
