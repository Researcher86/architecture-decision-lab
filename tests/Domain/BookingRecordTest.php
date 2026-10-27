<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Domain;

use ArchitectureDecisionLab\Domain\BookingLine;
use ArchitectureDecisionLab\Domain\BookingRecord;
use ArchitectureDecisionLab\Domain\BookingStatus;
use ArchitectureDecisionLab\Domain\ResourceType;
use ArchitectureDecisionLab\Domain\TripRequest;
use PHPUnit\Framework\TestCase;

final class BookingRecordTest extends TestCase
{
    public function testAmountIsTheSumOfPricedLines(): void
    {
        $booking = new BookingRecord('b1', 't1', 'f1', 'h1', 'c1', BookingStatus::Pending, 0, 10, 10);

        $booking = $booking
            ->withLines([
                new BookingLine(ResourceType::Flight, 'f1', 1, 12_000, 'committed'),
                new BookingLine(ResourceType::Hotel, 'h1', 2, 25_000, 'committed'),
                new BookingLine(ResourceType::Car, 'c1', 1, 8_000, 'committed'),
            ], 11)
            ->withStatus(BookingStatus::Confirmed, 12);

        self::assertSame(70_000, $booking->amountCents);
        self::assertSame(BookingStatus::Confirmed, $booking->status);
        self::assertSame(12, $booking->updatedAt);
        self::assertCount(3, $booking->lines);
    }

    public function testRoundTripThroughArray(): void
    {
        $booking = new BookingRecord(
            'b1',
            't1',
            'f1',
            'h1',
            'c1',
            BookingStatus::Cancelled,
            20_000,
            10,
            12,
            [new BookingLine(ResourceType::Flight, 'f1', 1, 12_000, 'released')],
        );

        $restored = BookingRecord::fromArray($booking->toArray());

        self::assertSame($booking->toArray(), $restored->toArray());
    }

    public function testTripRequestRejectsAnEmptyTraveler(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TripRequest('', 'f1', 'h1', 'c1');
    }

    public function testTripRequestRejectsZeroUnits(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TripRequest('t1', 'f1', 'h1', 'c1', 0);
    }
}
