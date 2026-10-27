<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Reporting;

/**
 * One row of the traveler report: the booking plus the current availability of
 * the resources it contains. Producing this row is the whole point of the
 * database-per-service chapter.
 */
final readonly class TripSummary
{
    public function __construct(
        public string $bookingId,
        public string $status,
        public int $flightAvailable,
        public int $hotelAvailable,
        public int $carAvailable,
    ) {
    }

    /**
     * @return array{booking_id: string, status: string, flight_available: int, hotel_available: int, car_available: int}
     */
    public function toArray(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'status' => $this->status,
            'flight_available' => $this->flightAvailable,
            'hotel_available' => $this->hotelAvailable,
            'car_available' => $this->carAvailable,
        ];
    }
}
