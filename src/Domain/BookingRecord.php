<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Domain;

/**
 * The booking aggregate. Lines are part of the aggregate; a document store
 * keeps them nested, a relational store normalizes them into booking_lines.
 */
final readonly class BookingRecord
{
    /**
     * @param list<BookingLine> $lines
     */
    public function __construct(
        public string $id,
        public string $travelerId,
        public string $flightId,
        public string $hotelId,
        public string $carId,
        public BookingStatus $status,
        public int $amountCents,
        public int $createdAt,
        public int $updatedAt,
        public array $lines = [],
    ) {
    }

    public function withStatus(BookingStatus $status, int $now): self
    {
        return new self(
            $this->id,
            $this->travelerId,
            $this->flightId,
            $this->hotelId,
            $this->carId,
            $status,
            $this->amountCents,
            $this->createdAt,
            $now,
            $this->lines,
        );
    }

    /**
     * @param list<BookingLine> $lines
     */
    public function withLines(array $lines, int $now): self
    {
        $amount = 0;

        foreach ($lines as $line) {
            $amount += $line->priceCents * $line->units;
        }

        return new self(
            $this->id,
            $this->travelerId,
            $this->flightId,
            $this->hotelId,
            $this->carId,
            $this->status,
            $amount,
            $this->createdAt,
            $now,
            $lines,
        );
    }

    /**
     * @return array{id: string, traveler_id: string, flight_id: string, hotel_id: string, car_id: string, status: string, amount_cents: int, created_at: int, updated_at: int, lines: list<array{type: string, id: string, units: int, price_cents: int, state: string}>}
     */
    public function toArray(): array
    {
        $lines = [];

        foreach ($this->lines as $line) {
            $lines[] = $line->toArray();
        }

        return [
            'id' => $this->id,
            'traveler_id' => $this->travelerId,
            'flight_id' => $this->flightId,
            'hotel_id' => $this->hotelId,
            'car_id' => $this->carId,
            'status' => $this->status->value,
            'amount_cents' => $this->amountCents,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'lines' => $lines,
        ];
    }

    /**
     * @param array{id: string, traveler_id: string, flight_id: string, hotel_id: string, car_id: string, status: string, amount_cents: int, created_at: int, updated_at: int, lines: list<array{type: string, id: string, units: int, price_cents: int, state: string}>} $data
     */
    public static function fromArray(array $data): self
    {
        $lines = [];

        foreach ($data['lines'] as $line) {
            $lines[] = BookingLine::fromArray($line);
        }

        return new self(
            $data['id'],
            $data['traveler_id'],
            $data['flight_id'],
            $data['hotel_id'],
            $data['car_id'],
            BookingStatus::from($data['status']),
            $data['amount_cents'],
            $data['created_at'],
            $data['updated_at'],
            $lines,
        );
    }
}
