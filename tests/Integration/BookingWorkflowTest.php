<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Integration;

use ArchitectureDecisionLab\Domain\BookingStatus;
use ArchitectureDecisionLab\Domain\DatabaseMode;
use ArchitectureDecisionLab\Domain\PaymentStatus;
use ArchitectureDecisionLab\Domain\ReservationStatus;
use ArchitectureDecisionLab\Domain\ResourceType;
use ArchitectureDecisionLab\Domain\TransactionMode;
use ArchitectureDecisionLab\Domain\TripRequest;
use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Tests\Support\LabTestCase;

final class BookingWorkflowTest extends LabTestCase
{
    public function testLocalTransactionConfirmsAPackageAndWritesOneOutboxEvent(): void
    {
        $lab = $this->lab();
        $lab->seed('f1', 'h1', 'c1', capacity: 4);

        $result = $lab->book($this->trip());

        self::assertTrue($result->accepted);
        self::assertSame(BookingStatus::Confirmed, $result->status);
        self::assertSame(45_000, $this->bookingAmount($lab->store->get($result->bookingId)?->amountCents));
        self::assertSame(3, $lab->sourceAvailability(ResourceType::Flight, 'f1'));
        self::assertSame(1, $lab->outbox->countPending());
        self::assertCount(3, $lab->store->lines($result->bookingId));
    }

    public function testLocalTransactionRollsBackAReservationWhoseResponseWasLost(): void
    {
        $counters = new Counters();
        $faults = new FaultInjector(step: 'car.write', after: true, counters: $counters);
        $lab = $this->lab($this->config(transaction: TransactionMode::Local), $faults, $counters);
        $lab->seed('f1', 'h1', 'c1', capacity: 4);

        $result = $lab->book($this->trip());

        self::assertFalse($result->accepted);
        self::assertSame(BookingStatus::Cancelled, $result->status);
        self::assertSame(4, $lab->sourceAvailability(ResourceType::Flight, 'f1'));
        self::assertSame(4, $lab->sourceAvailability(ResourceType::Hotel, 'h1'));
        self::assertSame(4, $lab->sourceAvailability(ResourceType::Car, 'c1'));
        self::assertCount(0, $lab->flight->reservations());
        self::assertSame(BookingStatus::Cancelled, $lab->store->get($result->bookingId)?->status);
    }

    public function testSagaCompensatesCompletedStepsWhenPaymentFails(): void
    {
        $counters = new Counters();
        $faults = new FaultInjector(step: 'payment.authorize', counters: $counters);
        $lab = $this->lab($this->config(transaction: TransactionMode::Saga), $faults, $counters);
        $lab->seed('f1', 'h1', 'c1', capacity: 4);

        $result = $lab->book($this->trip());

        self::assertFalse($result->accepted);
        self::assertSame(BookingStatus::Cancelled, $result->status);
        self::assertSame(4, $result->compensations);
        self::assertSame(4, $lab->sourceAvailability(ResourceType::Flight, 'f1'));
        self::assertSame(4, $lab->sourceAvailability(ResourceType::Hotel, 'h1'));
        self::assertSame(4, $lab->sourceAvailability(ResourceType::Car, 'c1'));
        self::assertSame(ReservationStatus::Released, $lab->flight->reservationStatus($result->bookingId, 'f1'));
        self::assertNull($lab->payment->status($result->bookingId));
        self::assertSame(3, $lab->counters->get('reservationsReleased'));
        self::assertSame(1, $lab->outbox->countPending());
    }

    public function testTwoPhaseCommitAbortsAllHoldsWhenACarPrepareFails(): void
    {
        $counters = new Counters();
        $faults = new FaultInjector(step: '2pc.prepare.car', counters: $counters);
        $lab = $this->lab($this->twoPhaseConfig(), $faults, $counters);
        $lab->seed('f1', 'h1', 'c1', capacity: 4);

        $result = $lab->book($this->trip());

        self::assertFalse($result->accepted);
        self::assertSame(BookingStatus::Cancelled, $result->status);
        self::assertSame(4, $lab->sourceAvailability(ResourceType::Flight, 'f1'));
        self::assertSame(4, $lab->sourceAvailability(ResourceType::Hotel, 'h1'));
        self::assertSame(4, $lab->sourceAvailability(ResourceType::Car, 'c1'));
        self::assertSame(ReservationStatus::Released, $lab->flight->reservationStatus($result->bookingId, 'f1'));
        self::assertNull($lab->payment->status($result->bookingId));
        self::assertSame(1, $lab->outbox->countPending());
    }

    public function testDualWritePublishFailureLeavesAConfirmedBookingWithoutAnEvent(): void
    {
        $counters = new Counters();
        $faults = new FaultInjector(step: 'dualwrite.publish', counters: $counters);
        $lab = $this->lab(
            $this->config(
                transaction: TransactionMode::Saga,
                delivery: \ArchitectureDecisionLab\Domain\DeliveryMode::DualWrite,
            ),
            $faults,
            $counters,
        );
        $lab->seed('f1', 'h1', 'c1', capacity: 4);

        $result = $lab->book($this->trip());

        self::assertSame(BookingStatus::Confirmed, $result->status);
        self::assertFalse($result->eventPublished);
        self::assertSame(1, $lab->counters->get('eventPublishFailures'));
        self::assertSame(0, $lab->projection->tripCount());
        self::assertSame(0, $lab->outbox->countPending());
    }

    public function testDualWriteLostResponseStillCountsTheEventAsPublished(): void
    {
        $counters = new Counters();
        $faults = new FaultInjector(step: 'dualwrite.publish', after: true, counters: $counters);
        $lab = $this->lab(
            $this->config(
                transaction: TransactionMode::Saga,
                delivery: \ArchitectureDecisionLab\Domain\DeliveryMode::DualWrite,
            ),
            $faults,
            $counters,
        );
        $lab->seed('f1', 'h1', 'c1', capacity: 4);

        $result = $lab->book($this->trip());

        self::assertSame(BookingStatus::Confirmed, $result->status);
        self::assertTrue($result->eventPublished);
        self::assertSame(1, $lab->projection->tripCount());
        self::assertSame(1, $lab->counters->get('eventPublishFailures'));
    }

    public function testTwoPhaseCommitCrashLeavesHoldsAndRecoveryCanCommitThem(): void
    {
        $counters = new Counters();
        $faults = new FaultInjector(step: '2pc.commit', counters: $counters);
        $lab = $this->lab($this->twoPhaseConfig(), $faults, $counters);
        $lab->seed('f1', 'h1', 'c1', capacity: 4);

        $result = $lab->book($this->trip());

        self::assertSame(BookingStatus::Pending, $result->status);
        self::assertSame(4, $lab->counters->get('twoPhaseHoldsGauge'));
        self::assertSame(ReservationStatus::Prepared, $lab->flight->reservationStatus($result->bookingId, 'f1'));
        self::assertSame(PaymentStatus::Prepared, $lab->payment->status($result->bookingId));
        self::assertSame(3, $lab->sourceAvailability(ResourceType::Flight, 'f1'));

        $booking = $lab->store->get($result->bookingId);
        self::assertNotNull($booking);
        $recovered = $lab->workflow->recoverTwoPhase($booking, 'commit');

        self::assertSame(BookingStatus::Confirmed, $recovered->status);
        self::assertSame(ReservationStatus::Committed, $lab->flight->reservationStatus($result->bookingId, 'f1'));
        self::assertSame(PaymentStatus::Captured, $lab->payment->status($result->bookingId));
        self::assertSame(3, $lab->sourceAvailability(ResourceType::Flight, 'f1'));
        self::assertSame(1, $lab->outbox->countPending());
    }

    public function testTwoPhaseCommitCrashCanBeRecoveredByAborting(): void
    {
        $counters = new Counters();
        $faults = new FaultInjector(step: '2pc.commit', counters: $counters);
        $lab = $this->lab($this->twoPhaseConfig(), $faults, $counters);
        $lab->seed('f1', 'h1', 'c1', capacity: 4);
        $result = $lab->book($this->trip());

        $booking = $lab->store->get($result->bookingId);
        self::assertNotNull($booking);
        $recovered = $lab->workflow->recoverTwoPhase($booking, 'abort');

        self::assertSame(BookingStatus::Cancelled, $recovered->status);
        self::assertSame(ReservationStatus::Released, $lab->flight->reservationStatus($result->bookingId, 'f1'));
        self::assertSame(PaymentStatus::Voided, $lab->payment->status($result->bookingId));
        self::assertSame(4, $lab->sourceAvailability(ResourceType::Flight, 'f1'));
    }

    private function twoPhaseConfig(): \ArchitectureDecisionLab\Integration\LabConfig
    {
        return $this->config(
            shape: \ArchitectureDecisionLab\Domain\DeploymentShape::ModularMonolith,
            database: DatabaseMode::PerService,
            transaction: TransactionMode::TwoPhaseCommit,
        );
    }

    private function trip(): TripRequest
    {
        return new TripRequest('t1', 'f1', 'h1', 'c1');
    }

    private function bookingAmount(?int $amount): int
    {
        return $amount ?? 0;
    }
}
