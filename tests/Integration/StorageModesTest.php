<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Integration;

use ArchitectureDecisionLab\Domain\StorageMode;
use ArchitectureDecisionLab\Domain\TripRequest;
use ArchitectureDecisionLab\Tests\Support\LabTestCase;

final class StorageModesTest extends LabTestCase
{
    public function testSqlAndDocumentStoresReturnTheSameAggregate(): void
    {
        $sql = $this->lab($this->config(storage: StorageMode::Sql));
        $document = $this->lab($this->config(storage: StorageMode::Document));
        $sql->seed('f1', 'h1', 'c1', capacity: 10);
        $document->seed('f1', 'h1', 'c1', capacity: 10);

        $sqlResult = $sql->book(new TripRequest('t1', 'f1', 'h1', 'c1'));
        $documentResult = $document->book(new TripRequest('t1', 'f1', 'h1', 'c1'));

        $sqlBooking = $sql->store->get($sqlResult->bookingId);
        $documentBooking = $document->store->get($documentResult->bookingId);

        self::assertNotNull($sqlBooking);
        self::assertNotNull($documentBooking);
        self::assertSame($sqlBooking->amountCents, $documentBooking->amountCents);
        self::assertCount(3, $sqlBooking->lines);
        self::assertCount(3, $documentBooking->lines);
        self::assertSame(
            array_map(static fn ($line): array => $line->toArray(), $sqlBooking->lines),
            array_map(static fn ($line): array => $line->toArray(), $documentBooking->lines),
        );
    }

    public function testTravelerQueryScansEveryDocumentButUsesOneJoinInSql(): void
    {
        $sql = $this->lab($this->config(storage: StorageMode::Sql));
        $document = $this->lab($this->config(storage: StorageMode::Document));
        $sql->seed('f1', 'h1', 'c1', capacity: 20);
        $document->seed('f1', 'h1', 'c1', capacity: 20);

        foreach (['t1', 't1', 't1', 't2', 't2'] as $traveler) {
            $sql->book(new TripRequest($traveler, 'f1', 'h1', 'c1'));
            $document->book(new TripRequest($traveler, 'f1', 'h1', 'c1'));
        }

        $sql->resetCounters();
        $document->resetCounters();

        $sqlBookings = $sql->store->byTraveler('t1');
        $documentBookings = $document->store->byTraveler('t1');

        self::assertCount(3, $sqlBookings);
        self::assertCount(3, $documentBookings);
        self::assertSame(1, $sql->counters->get('sqlQueries'));
        self::assertSame(5, $document->counters->get('documentsScanned'));
        self::assertSame(3, $document->counters->get('documentsRead'));
    }

    public function testPointReadCostsTwoSqlQueriesAndOneDocumentQuery(): void
    {
        $sql = $this->lab($this->config(storage: StorageMode::Sql));
        $document = $this->lab($this->config(storage: StorageMode::Document));
        $sql->seed('f1', 'h1', 'c1');
        $document->seed('f1', 'h1', 'c1');
        $sqlResult = $sql->book(new TripRequest('t1', 'f1', 'h1', 'c1'));
        $documentResult = $document->book(new TripRequest('t1', 'f1', 'h1', 'c1'));

        $sql->resetCounters();
        $document->resetCounters();

        self::assertNotNull($sql->store->get($sqlResult->bookingId));
        self::assertNotNull($document->store->get($documentResult->bookingId));

        self::assertSame(2, $sql->counters->get('sqlQueries'));
        self::assertSame(1, $document->counters->get('sqlQueries'));
        self::assertSame(1, $document->counters->get('documentsRead'));
    }
}
