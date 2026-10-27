<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Services;

use ArchitectureDecisionLab\Consistency\EventEnvelope;
use ArchitectureDecisionLab\Consistency\InMemoryEventBus;
use ArchitectureDecisionLab\Domain\BookingFailure;
use ArchitectureDecisionLab\Domain\BookingLine;
use ArchitectureDecisionLab\Domain\BookingRecord;
use ArchitectureDecisionLab\Domain\BookingResult;
use ArchitectureDecisionLab\Domain\BookingStatus;
use ArchitectureDecisionLab\Domain\DeliveryMode;
use ArchitectureDecisionLab\Domain\PaymentResult;
use ArchitectureDecisionLab\Domain\ReservationResult;
use ArchitectureDecisionLab\Domain\TransactionMode;
use ArchitectureDecisionLab\Domain\TripRequest;
use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Foundation\SequentialIdGenerator;
use ArchitectureDecisionLab\Foundation\VirtualClock;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Saga\Participant;
use ArchitectureDecisionLab\Saga\SagaCoordinator;
use ArchitectureDecisionLab\Saga\SagaStep;
use ArchitectureDecisionLab\Saga\TwoPhaseCommitCoordinator;
use ArchitectureDecisionLab\Storage\BookingStore;

/**
 * The booking use case, independent of how the decisions are configured.
 *
 * The same steps run under a local transaction, a saga, a two-phase commit or
 * with no transaction at all. The difference is in the boundary, not in the
 * business rule: reserve flight, hotel and car, authorize payment, confirm.
 */
final readonly class BookingWorkflow
{
    public function __construct(
        private BookingStore $store,
        private InventoryService $flight,
        private InventoryService $hotel,
        private InventoryService $car,
        private PaymentService $payment,
        private InMemoryEventBus $bus,
        private VirtualClock $clock,
        private Counters $counters,
        private FaultInjector $faults,
        private DeliveryMode $delivery,
        private TransactionMode $transaction,
        private SequentialIdGenerator $ids,
        private bool $localTransactionIsAtomic,
    ) {
        if ($transaction === TransactionMode::Local && !$localTransactionIsAtomic) {
            throw new \InvalidArgumentException('A local transaction cannot cover separate databases');
        }
    }

    public function submit(TripRequest $request): BookingRecord
    {
        $now = $this->clock->now();
        $booking = new BookingRecord(
            $this->ids->next('trip'),
            $request->travelerId,
            $request->flightId,
            $request->hotelId,
            $request->carId,
            BookingStatus::Pending,
            0,
            $now,
            $now,
        );

        $this->store->create($booking);
        $this->counters->increment('bookingsSubmitted');
        $this->clock->advance(1);

        return $booking;
    }

    public function execute(BookingRecord $booking, int $index = 0): BookingResult
    {
        $this->faults->forBooking($index);
        $startedAt = $this->clock->now();

        $result = match ($this->transaction) {
            TransactionMode::Local => $this->executeLocal($booking, $startedAt),
            TransactionMode::Saga => $this->executeSaga($booking, $startedAt),
            TransactionMode::TwoPhaseCommit => $this->executeTwoPhase($booking, $startedAt),
            TransactionMode::None => $this->executeWithoutTransaction($booking, $startedAt),
        };

        return $result;
    }

    private function executeLocal(BookingRecord $booking, int $startedAt): BookingResult
    {
        try {
            $lines = $this->store->transaction(function () use ($booking): array {
                $lines = $this->reserveAll($booking->id);
                $this->finalize($booking, BookingStatus::Confirmed, $lines);

                return $lines;
            });
        } catch (\Throwable $throwable) {
            $this->finalize($booking, BookingStatus::Cancelled, [], $throwable->getMessage());

            return new BookingResult(
                $booking->id,
                BookingStatus::Cancelled,
                false,
                $this->clock->now() - $startedAt,
                $this->clock->now() - $startedAt,
                0,
                $throwable->getMessage(),
            );
        }

        return new BookingResult(
            $booking->id,
            BookingStatus::Confirmed,
            true,
            $this->clock->now() - $startedAt,
            $this->clock->now() - $startedAt,
            0,
            '',
            $lines,
        );
    }

    private function executeSaga(BookingRecord $booking, int $startedAt): BookingResult
    {
        $lines = [];
        $amount = 0;
        $steps = [];

        $steps[] = new SagaStep(
            'flight.reserve',
            function () use ($booking, &$lines, &$amount): void {
                $result = $this->flight->reserve($booking->id, $booking->flightId, 1);
                $this->requireSuccess($result);
                $lines[] = $result->line();
                $amount += $result->priceCents;
            },
            function () use ($booking): void {
                $this->flight->release($booking->id, $booking->flightId);
            },
        );

        $steps[] = new SagaStep(
            'hotel.reserve',
            function () use ($booking, &$lines, &$amount): void {
                $result = $this->hotel->reserve($booking->id, $booking->hotelId, 1);
                $this->requireSuccess($result);
                $lines[] = $result->line();
                $amount += $result->priceCents;
            },
            function () use ($booking): void {
                $this->hotel->release($booking->id, $booking->hotelId);
            },
        );

        $steps[] = new SagaStep(
            'car.reserve',
            function () use ($booking, &$lines, &$amount): void {
                $result = $this->car->reserve($booking->id, $booking->carId, 1);
                $this->requireSuccess($result);
                $lines[] = $result->line();
                $amount += $result->priceCents;
            },
            function () use ($booking): void {
                $this->car->release($booking->id, $booking->carId);
            },
        );

        $steps[] = new SagaStep(
            'payment.authorize',
            function () use ($booking, &$amount): void {
                $payment = $this->payment->authorize($booking->id, $amount);
                $this->requirePayment($payment);
            },
            function () use ($booking): void {
                $this->payment->void($booking->id);
            },
        );

        $saga = $this->saga()->run($steps);

        if ($saga->success) {
            $this->finalize($booking, BookingStatus::Confirmed, $lines);

            return new BookingResult(
                $booking->id,
                BookingStatus::Confirmed,
                true,
                $this->clock->now() - $startedAt,
                $this->clock->now() - $startedAt,
                0,
                '',
                $lines,
            );
        }

        $released = $this->released($lines);
        $this->finalize($booking, BookingStatus::Cancelled, $released, $saga->reason);

        return new BookingResult(
            $booking->id,
            BookingStatus::Cancelled,
            false,
            $this->clock->now() - $startedAt,
            $this->clock->now() - $startedAt,
            $saga->compensationAttempts,
            $saga->reason,
            $released,
        );
    }

    private function executeTwoPhase(BookingRecord $booking, int $startedAt): BookingResult
    {
        $lines = [];
        $amount = 0;
        $coordinator = new TwoPhaseCommitCoordinator($this->counters, $this->faults);

        $participants = [
            $this->participant(
                $this->flight,
                $booking,
                $booking->flightId,
                $lines,
                $amount,
            ),
            $this->participant(
                $this->hotel,
                $booking,
                $booking->hotelId,
                $lines,
                $amount,
            ),
            $this->participant(
                $this->car,
                $booking,
                $booking->carId,
                $lines,
                $amount,
            ),
            new Participant(
                'payment',
                function () use ($booking, &$amount): void {
                    $payment = $this->payment->prepare($booking->id, $amount);
                    $this->requirePayment($payment);
                },
                function () use ($booking): void {
                    $this->payment->capture($booking->id);
                },
                function () use ($booking): void {
                    $this->payment->void($booking->id);
                },
            ),
        ];

        $result = $coordinator->execute($participants);

        /** @var list<BookingLine> $lines */
        if ($result->status === 'committed') {
            $this->finalize($booking, BookingStatus::Confirmed, $lines);

            return new BookingResult(
                $booking->id,
                BookingStatus::Confirmed,
                true,
                $this->clock->now() - $startedAt,
                $this->clock->now() - $startedAt,
                0,
                '',
                $lines,
            );
        }

        if ($result->status === 'aborted') {
            $released = $this->released($lines);
            $this->finalize($booking, BookingStatus::Cancelled, $released, $result->reason);

            return new BookingResult(
                $booking->id,
                BookingStatus::Cancelled,
                false,
                $this->clock->now() - $startedAt,
                $this->clock->now() - $startedAt,
                count($result->aborted),
                $result->reason,
                $released,
            );
        }

        $this->finalize($booking, BookingStatus::Pending, $lines, $result->reason);

        return new BookingResult(
            $booking->id,
            BookingStatus::Pending,
            false,
            $this->clock->now() - $startedAt,
            $this->clock->now() - $startedAt,
            0,
            $result->reason,
            $lines,
        );
    }

    public function recoverTwoPhase(BookingRecord $booking, string $decision): BookingResult
    {
        if ($this->transaction !== TransactionMode::TwoPhaseCommit) {
            throw new BookingFailure('Only a two-phase-commit booking can be recovered');
        }

        if ($booking->status->isTerminal()) {
            throw new BookingFailure(sprintf('Booking %s is already %s', $booking->id, $booking->status->value));
        }

        $startedAt = $this->clock->now();
        $lines = $booking->lines;
        $coordinator = new TwoPhaseCommitCoordinator($this->counters, $this->faults);
        $participants = [
            $this->recoveryParticipant($this->flight, $booking, $booking->flightId),
            $this->recoveryParticipant($this->hotel, $booking, $booking->hotelId),
            $this->recoveryParticipant($this->car, $booking, $booking->carId),
            new Participant(
                'payment',
                static function (): void {
                },
                function () use ($booking): void {
                    $this->payment->capture($booking->id);
                },
                function () use ($booking): void {
                    $this->payment->void($booking->id);
                },
            ),
        ];

        $result = $coordinator->recover($participants, $decision);

        if ($decision === 'commit') {
            $this->finalize($booking, BookingStatus::Confirmed, $lines);

            return new BookingResult(
                $booking->id,
                BookingStatus::Confirmed,
                true,
                $this->clock->now() - $startedAt,
                $this->clock->now() - $startedAt,
                0,
                '',
                $lines,
            );
        }

        $released = $this->released($lines);
        $this->finalize($booking, BookingStatus::Cancelled, $released, 'recovered abort');

        return new BookingResult(
            $booking->id,
            BookingStatus::Cancelled,
            false,
            $this->clock->now() - $startedAt,
            $this->clock->now() - $startedAt,
            count($result->aborted),
            'recovered abort',
            $released,
        );
    }

    private function recoveryParticipant(
        InventoryService $service,
        BookingRecord $booking,
        string $resourceId,
    ): Participant {
        return new Participant(
            $service->type()->value,
            static function (): void {
            },
            function () use ($service, $booking, $resourceId): void {
                $service->commit($booking->id, $resourceId);
            },
            function () use ($service, $booking, $resourceId): void {
                $service->release($booking->id, $resourceId);
            },
        );
    }

    private function executeWithoutTransaction(BookingRecord $booking, int $startedAt): BookingResult
    {
        try {
            $lines = $this->reserveAll($booking->id);
            $this->finalize($booking, BookingStatus::Confirmed, $lines);
        } catch (\Throwable $throwable) {
            $this->finalize($booking, BookingStatus::Failed, [], $throwable->getMessage());

            return new BookingResult(
                $booking->id,
                BookingStatus::Failed,
                false,
                $this->clock->now() - $startedAt,
                $this->clock->now() - $startedAt,
                0,
                $throwable->getMessage(),
            );
        }

        return new BookingResult(
            $booking->id,
            BookingStatus::Confirmed,
            true,
            $this->clock->now() - $startedAt,
            $this->clock->now() - $startedAt,
            0,
            '',
            $lines,
        );
    }

    /**
     * @return list<BookingLine>
     */
    private function reserveAll(string $bookingId): array
    {
        $booking = $this->store->get($bookingId) ?? throw new BookingFailure('booking disappeared');

        $lines = [];
        $amount = 0;

        foreach ([$this->flight, $this->hotel, $this->car] as $service) {
            $resourceId = match ($service->type()->value) {
                'flight' => $booking->flightId,
                'hotel' => $booking->hotelId,
                'car' => $booking->carId,
            };

            $result = $service->reserve($bookingId, $resourceId, 1);
            $this->requireSuccess($result);
            $lines[] = $result->line();
            $amount += $result->priceCents;
        }

        $payment = $this->payment->authorize($bookingId, $amount);
        $this->requirePayment($payment);

        return $lines;
    }

    /**
     * @param list<BookingLine> $lines
     */
    private function finalize(BookingRecord $booking, BookingStatus $status, array $lines, string $reason = ''): void
    {
        $now = $this->clock->now();
        $record = $booking->withStatus($status, $now)->withLines($lines, $now);

        if ($status !== BookingStatus::Confirmed && $status !== BookingStatus::Cancelled) {
            $this->store->save($record);

            if ($status === BookingStatus::Failed) {
                $this->counters->increment('bookingsFailed');
            }

            return;
        }

        $event = new EventEnvelope(
            $this->ids->next('evt'),
            $status === BookingStatus::Confirmed ? 'booking.confirmed' : 'booking.cancelled',
            [
                'booking_id' => $record->id,
                'traveler_id' => $record->travelerId,
                'amount_cents' => $record->amountCents,
                'lines' => array_map(static fn (BookingLine $line): array => $line->toArray(), $lines),
                'reason' => $reason,
            ],
            $now,
        );

        if ($this->delivery === DeliveryMode::Outbox) {
            $this->store->save($record, $event);
        } else {
            $this->store->save($record);
            $this->faults->before('dualwrite.publish');
            $this->bus->publish($event);
            $this->faults->after('dualwrite.publish');
        }

        $this->counters->increment($status === BookingStatus::Confirmed ? 'bookingsConfirmed' : 'bookingsCancelled');
    }

    /**
     * @param list<BookingLine> $lines
     */
    private function participant(
        InventoryService $service,
        BookingRecord $booking,
        string $resourceId,
        array &$lines,
        int &$amount,
    ): Participant {
        return new Participant(
            $service->type()->value,
            function () use ($service, $booking, $resourceId, &$lines, &$amount): void {
                $result = $service->prepare($booking->id, $resourceId, 1);
                $this->requireSuccess($result);
                $lines[] = $result->line('committed');
                $amount += $result->priceCents;
            },
            function () use ($service, $booking, $resourceId): void {
                $service->commit($booking->id, $resourceId);
            },
            function () use ($service, $booking, $resourceId): void {
                $service->release($booking->id, $resourceId);
            },
        );
    }

    private function requireSuccess(ReservationResult $result): void
    {
        if (!$result->success) {
            throw new BookingFailure($result->reason);
        }
    }

    private function requirePayment(PaymentResult $result): void
    {
        if (!$result->success) {
            throw new BookingFailure($result->reason);
        }
    }

    /**
     * @param list<BookingLine> $lines
     *
     * @return list<BookingLine>
     */
    private function released(array $lines): array
    {
        $released = [];

        foreach ($lines as $line) {
            $released[] = new BookingLine(
                $line->resourceType,
                $line->resourceId,
                $line->units,
                $line->priceCents,
                'released',
            );
        }

        return $released;
    }

    private function saga(): SagaCoordinator
    {
        return new SagaCoordinator($this->counters);
    }
}
