<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Services;

use ArchitectureDecisionLab\Domain\PaymentStatus;
use ArchitectureDecisionLab\Domain\ReservationStatus;
use ArchitectureDecisionLab\Domain\ResourceType;
use ArchitectureDecisionLab\Tests\Support\LabTestCase;

final class InventoryAndPaymentTest extends LabTestCase
{
    public function testReservationIsIdempotentAndReleaseRestoresCapacity(): void
    {
        $lab = $this->lab();
        $lab->seed('f1', 'h1', 'c1', capacity: 2);

        $first = $lab->flight->reserve('b1', 'f1', 1);
        $second = $lab->flight->reserve('b1', 'f1', 1);

        self::assertTrue($first->success);
        self::assertTrue($second->success);
        self::assertSame(1, $lab->counters->get('idempotentReservations'));
        self::assertSame(1, $lab->sourceAvailability(ResourceType::Flight, 'f1'));

        $lab->flight->release('b1', 'f1');
        $lab->flight->release('b1', 'f1');

        self::assertSame(2, $lab->sourceAvailability(ResourceType::Flight, 'f1'));
        self::assertSame(1, $lab->counters->get('idempotentReleases'));
        self::assertSame(ReservationStatus::Released, $lab->flight->reservationStatus('b1', 'f1'));
    }

    public function testSoldOutReservationDoesNotWriteARow(): void
    {
        $lab = $this->lab();
        $lab->seed('f1', 'h1', 'c1', capacity: 1);

        $result = $lab->flight->reserve('b1', 'f1', 1);
        $soldOut = $lab->flight->reserve('b2', 'f1', 1);

        self::assertTrue($result->success);
        self::assertFalse($soldOut->success);
        self::assertSame('sold out', $soldOut->reason);
        self::assertSame(0, $lab->sourceAvailability(ResourceType::Flight, 'f1'));
        self::assertNull($lab->flight->reservationStatus('b2', 'f1'));
    }

    public function testUnknownResourceIsARejectedReservation(): void
    {
        $lab = $this->lab();

        $result = $lab->flight->reserve('b1', 'missing', 1);

        self::assertFalse($result->success);
        self::assertSame('unknown resource', $result->reason);
    }

    public function testPrepareThenCommitChangesTheReservationStatus(): void
    {
        $lab = $this->lab();
        $lab->seed('f1', 'h1', 'c1', capacity: 2);

        $prepared = $lab->flight->prepare('b1', 'f1', 1);
        self::assertTrue($prepared->success);
        self::assertSame(ReservationStatus::Prepared, $lab->flight->reservationStatus('b1', 'f1'));

        $lab->flight->commit('b1', 'f1');
        $lab->flight->commit('b1', 'f1');

        self::assertSame(ReservationStatus::Committed, $lab->flight->reservationStatus('b1', 'f1'));
        self::assertSame(1, $lab->counters->get('idempotentCommits'));
        self::assertSame(1, $lab->sourceAvailability(ResourceType::Flight, 'f1'));
    }

    public function testPaymentLifecycleIsIdempotent(): void
    {
        $lab = $this->lab();

        $authorized = $lab->payment->authorize('b1', 45_000);
        $again = $lab->payment->authorize('b1', 45_000);

        self::assertTrue($authorized->success);
        self::assertTrue($again->success);
        self::assertSame(PaymentStatus::Authorized, $lab->payment->status('b1'));
        self::assertSame(1, $lab->counters->get('idempotentPayments'));

        $lab->payment->capture('b1');
        $lab->payment->capture('b1');

        self::assertSame(PaymentStatus::Captured, $lab->payment->status('b1'));
        self::assertSame(1, $lab->counters->get('idempotentCaptures'));

        $lab->payment->void('b1');
        $lab->payment->void('b1');

        self::assertSame(PaymentStatus::Voided, $lab->payment->status('b1'));
        self::assertSame(1, $lab->counters->get('idempotentVoids'));
    }

    public function testVoidOfAnUnknownPaymentIsANoOp(): void
    {
        $lab = $this->lab();

        $lab->payment->void('missing');

        self::assertNull($lab->payment->status('missing'));
        self::assertSame(1, $lab->counters->get('paymentVoidNoops'));
    }
}
