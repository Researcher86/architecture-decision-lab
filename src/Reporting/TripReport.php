<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Reporting;

interface TripReport
{
    /**
     * @return list<TripSummary>
     */
    public function forTraveler(string $travelerId): array;

    public function kind(): string;
}
