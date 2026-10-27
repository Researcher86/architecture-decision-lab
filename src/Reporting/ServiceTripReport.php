<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Reporting;

use ArchitectureDecisionLab\Services\InventoryService;
use ArchitectureDecisionLab\Storage\BookingStore;

/**
 * The report a service boundary forces: read the bookings from the booking
 * service, then ask the three inventory services about every resource. The
 * result is the same; the number of calls and the window for staleness differ.
 */
final readonly class ServiceTripReport implements TripReport
{
    public function __construct(
        private BookingStore $store,
        private InventoryService $flight,
        private InventoryService $hotel,
        private InventoryService $car,
    ) {
    }

    public function forTraveler(string $travelerId): array
    {
        $summaries = [];

        foreach ($this->store->byTraveler($travelerId) as $booking) {
            $summaries[] = new TripSummary(
                $booking->id,
                $booking->status->value,
                $this->flight->available($booking->flightId),
                $this->hotel->available($booking->hotelId),
                $this->car->available($booking->carId),
            );
        }

        return $summaries;
    }

    public function kind(): string
    {
        return 'service-calls';
    }
}
