<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Integration;

use ArchitectureDecisionLab\Domain\BookingResult;
use ArchitectureDecisionLab\Domain\BookingStatus;
use ArchitectureDecisionLab\Domain\ConsistencyMode;
use ArchitectureDecisionLab\Domain\DatabaseMode;
use ArchitectureDecisionLab\Domain\DeliveryMode;
use ArchitectureDecisionLab\Domain\DeploymentShape;
use ArchitectureDecisionLab\Domain\PaymentStatus;
use ArchitectureDecisionLab\Domain\ProcessingMode;
use ArchitectureDecisionLab\Domain\ReservationStatus;
use ArchitectureDecisionLab\Domain\ResourceType;
use ArchitectureDecisionLab\Domain\StorageMode;
use ArchitectureDecisionLab\Domain\TransactionMode;
use ArchitectureDecisionLab\Domain\TripRequest;
use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Instrumentation\Counters;

/**
 * The MVP scenario: a traveller books a flight, a hotel and a car as one
 * package. The first booking succeeds; the second is failed by payment and has
 * to compensate the three reservations. The outbox then carries both outcomes
 * to a lagging projection.
 *
 * The scenario fixes the decisions on purpose. It is a demonstration of the
 * complete flow, not a comparison; the chapters vary one decision at a time.
 */
final class TripBookingScenario
{
    private const CAPACITY = 4;

    public static function run(ScenarioOptions $options): ScenarioReport
    {
        $counters = new Counters();
        $faults = $options->mode === 'compensate'
            ? new FaultInjector(step: 'payment.authorize', failEvery: 2, counters: $counters)
            : new FaultInjector(counters: $counters);

        $config = new LabConfig(
            shape: DeploymentShape::Microservices,
            processing: ProcessingMode::Sync,
            storage: StorageMode::Sql,
            database: DatabaseMode::PerService,
            delivery: DeliveryMode::Outbox,
            transaction: TransactionMode::Saga,
            consistency: ConsistencyMode::Eventual,
            seed: $options->seed,
            projectionLagMs: $options->projectionLagMs,
            remoteLatencyMs: $options->remoteLatencyMs,
            queueLimit: 100,
            databaseDirectory: $options->databaseDirectory,
        );

        $lab = LabFactory::create($config, $faults, $counters);
        $timeline = [];
        $lab->seed('flight-1', 'hotel-1', 'car-1', self::CAPACITY);
        $timeline[] = sprintf(
            't=%d seeded flight-1, hotel-1 and car-1 with %d units each',
            $lab->clock->now(),
            self::CAPACITY,
        );

        $first = $lab->book(new TripRequest('traveler-1', 'flight-1', 'hotel-1', 'car-1'), 0);
        $timeline[] = sprintf(
            't=%d booking %s: %s after %d ms, compensation attempts %d',
            $lab->clock->now(),
            $first->bookingId,
            $first->status->value,
            $first->clientLatencyMs,
            $first->compensations,
        );

        $second = null;

        if ($options->mode === 'compensate') {
            $second = $lab->book(new TripRequest('traveler-2', 'flight-1', 'hotel-1', 'car-1'), 1);
            $timeline[] = sprintf(
                't=%d booking %s: %s after %d ms, compensation attempts %d (%s)',
                $lab->clock->now(),
                $second->bookingId,
                $second->status->value,
                $second->clientLatencyMs,
                $second->compensations,
                $second->reason,
            );
        }

        $pendingBefore = $lab->outbox->countPending();
        $staleFirst = $lab->projection->tripStatus($first->bookingId);
        $staleFlight = $lab->projectedAvailability(ResourceType::Flight, 'flight-1');
        $timeline[] = sprintf(
            't=%d outbox has %d pending event(s); the eventual projection still reports booking %s as %s',
            $lab->clock->now(),
            $pendingBefore,
            $first->bookingId,
            $staleFirst ?? 'absent',
        );

        $dispatch = $lab->dispatchOutbox();
        $timeline[] = sprintf(
            't=%d outbox dispatch delivered %d of %d event(s), %d pending',
            $lab->clock->now(),
            $dispatch->delivered,
            $dispatch->attempted,
            $dispatch->pending,
        );

        $drained = $lab->drainProjection();
        $timeline[] = sprintf(
            't=%d projection drained %d event(s); booking %s is now %s',
            $lab->clock->now(),
            $drained,
            $first->bookingId,
            $lab->projection->tripStatus($first->bookingId) ?? 'absent',
        );

        $summary = [
            'bookings' => $second === null ? 1 : 2,
            'confirmed' => $lab->counts()['bookings_confirmed'],
            'cancelled' => $lab->counts()['bookings_cancelled'],
            'compensation_attempts' => $second === null ? 0 : $second->compensations,
            'outbox_pending_before_dispatch' => $pendingBefore,
            'outbox_delivered' => $dispatch->delivered,
            'projection_events_drained' => $drained,
            'projection_stale_reads' => ($staleFirst === null ? 1 : 0) + ($staleFlight === self::CAPACITY ? 1 : 0),
            'flight_available_source' => $lab->sourceAvailability(ResourceType::Flight, 'flight-1'),
            'flight_available_projected' => $lab->projectedAvailability(ResourceType::Flight, 'flight-1') ?? -1,
            'projection_trips' => $lab->projection->tripCount(),
            'outbox_pending_after' => $lab->outbox->countPending(),
        ];

        $invariants = self::checkInvariants($lab, $first, $second);

        return new ScenarioReport(
            $options->mode,
            $config,
            $timeline,
            $summary,
            $invariants,
            $invariants === [],
            $counters->toArray(),
        );
    }

    /**
     * @return list<string> violations; an empty list means the scenario passed
     */
    private static function checkInvariants(Lab $lab, BookingResult $first, ?BookingResult $second): array
    {
        $violations = [];

        if ($first->status !== BookingStatus::Confirmed) {
            $violations[] = 'the first booking is not confirmed';
        }

        foreach ([ResourceType::Flight, ResourceType::Hotel, ResourceType::Car] as $type) {
            $id = match ($type) {
                ResourceType::Flight => 'flight-1',
                ResourceType::Hotel => 'hotel-1',
                ResourceType::Car => 'car-1',
            };
            $source = $lab->sourceAvailability($type, $id);
            $projected = $lab->projectedAvailability($type, $id);

            if ($source !== self::CAPACITY - 1) {
                $violations[] = sprintf('%s source availability is %d, expected %d', $type->value, $source, self::CAPACITY - 1);
            }

            if ($projected !== $source) {
                $violations[] = sprintf('%s projection is %s, source is %d', $type->value, $projected === null ? 'missing' : (string) $projected, $source);
            }

            if ($lab->service($type)->reservationStatus($first->bookingId, $id) !== ReservationStatus::Committed) {
                $violations[] = sprintf('%s reservation of the confirmed booking is not committed', $type->value);
            }
        }

        if ($lab->payment->status($first->bookingId) !== PaymentStatus::Authorized) {
            $violations[] = 'the confirmed booking has no authorized payment';
        }

        if ($lab->outbox->countPending() !== 0) {
            $violations[] = 'the outbox still has pending events';
        }

        if ($lab->projection->tripCount() !== ($second === null ? 1 : 2)) {
            $violations[] = 'the projection does not have one trip per booking';
        }

        if ($second !== null) {
            if ($second->status !== BookingStatus::Cancelled) {
                $violations[] = 'the second booking is not cancelled';
            }

            if ($second->compensations < 4) {
                $violations[] = 'the second booking did not compensate every completed step';
            }

            foreach ([ResourceType::Flight, ResourceType::Hotel, ResourceType::Car] as $type) {
                $id = match ($type) {
                    ResourceType::Flight => 'flight-1',
                    ResourceType::Hotel => 'hotel-1',
                    ResourceType::Car => 'car-1',
                };

                if ($lab->service($type)->reservationStatus($second->bookingId, $id) !== ReservationStatus::Released) {
                    $violations[] = sprintf('%s reservation of the cancelled booking is not released', $type->value);
                }
            }

            if ($lab->payment->status($second->bookingId) !== null) {
                $violations[] = 'the cancelled booking left a payment record';
            }

            if ($lab->projection->tripStatus($second->bookingId) !== 'cancelled') {
                $violations[] = 'the projection did not record the cancellation';
            }
        }

        if ($lab->projection->tripStatus($first->bookingId) !== 'confirmed') {
            $violations[] = 'the projection did not converge to the confirmed booking';
        }

        return $violations;
    }
}
