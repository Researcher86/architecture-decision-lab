<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Consistency;

use ArchitectureDecisionLab\Domain\ResourceType;
use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Integration\LabConfig;
use ArchitectureDecisionLab\Tests\Support\LabTestCase;

final class OutboxAndProjectionTest extends LabTestCase
{
    public function testOutboxCarriesTheEventFromCommitToTheProjection(): void
    {
        $lab = $this->lab();
        $lab->seed('f1', 'h1', 'c1', capacity: 4);

        $result = $lab->book($this->trip());
        self::assertTrue($result->accepted);
        self::assertSame(1, $lab->outbox->countPending());
        self::assertSame(4, $lab->projectedAvailability(ResourceType::Flight, 'f1'));

        $report = $lab->dispatchOutbox();

        self::assertSame(1, $report->delivered);
        self::assertSame(0, $lab->outbox->countPending());
        self::assertSame('confirmed', $lab->projection->tripStatus($result->bookingId));
        self::assertSame(3, $lab->projectedAvailability(ResourceType::Flight, 'f1'));
    }

    public function testDuplicateDeliveryIsAbsorbedByTheIdempotentProjector(): void
    {
        $lab = $this->lab();
        $lab->seed('f1', 'h1', 'c1', capacity: 4);

        $result = $lab->book($this->trip());
        $pending = $lab->outbox->pending(1);
        self::assertCount(1, $pending);
        $event = $pending[0]->event;

        $lab->bus->publish($event);
        $lab->bus->publish($event);

        self::assertSame(1, $lab->counters->get('duplicateEvents'));
        self::assertSame(1, $lab->projection->tripCount());
        self::assertSame(3, $lab->projectedAvailability(ResourceType::Flight, 'f1'));
    }

    public function testCrashBeforePublishLeavesTheEventPendingForTheNextDispatch(): void
    {
        $counters = new Counters();
        $faults = new FaultInjector(step: 'outbox.publish', counters: $counters);
        $lab = $this->lab($this->config(), $faults, $counters);
        $lab->seed('f1', 'h1', 'c1', capacity: 4);
        $lab->book($this->trip());

        $first = $lab->dispatchOutbox();

        self::assertSame(1, $first->failed);
        self::assertSame(1, $first->pending);
        self::assertSame(0, $lab->projection->tripCount());

        $second = $lab->dispatchOutbox();

        self::assertSame(1, $second->delivered);
        self::assertSame(0, $second->pending);
        self::assertSame(1, $lab->projection->tripCount());
    }

    public function testCrashAfterPublishProducesADuplicateThatTheConsumerIgnores(): void
    {
        $counters = new Counters();
        $faults = new FaultInjector(step: 'outbox.publish', after: true, counters: $counters);
        $lab = $this->lab($this->config(), $faults, $counters);
        $lab->seed('f1', 'h1', 'c1', capacity: 4);
        $lab->book($this->trip());

        $first = $lab->dispatchOutbox();

        self::assertSame(1, $first->failed);
        self::assertSame(1, $first->pending);
        self::assertSame(1, $lab->projection->tripCount());

        $second = $lab->dispatchOutbox();

        self::assertSame(1, $second->delivered);
        self::assertSame(0, $second->pending);
        self::assertSame(1, $lab->projection->tripCount());
        self::assertSame(1, $lab->counters->get('duplicateEvents'));
        self::assertSame(3, $lab->projectedAvailability(ResourceType::Flight, 'f1'));
    }

    public function testEventualProjectionStaysStaleUntilTheRunnerDrains(): void
    {
        $lab = $this->lab(new LabConfig(
            databaseDirectory: $this->directory,
            seed: 'test',
            consistency: \ArchitectureDecisionLab\Domain\ConsistencyMode::Eventual,
            projectionLagMs: 50,
        ));
        $lab->seed('f1', 'h1', 'c1', capacity: 4);

        $result = $lab->book($this->trip());
        $lab->dispatchOutbox();

        self::assertSame(1, $lab->projectionRunner->pending());
        self::assertNull($lab->projection->tripStatus($result->bookingId));
        self::assertSame(4, $lab->projectedAvailability(ResourceType::Flight, 'f1'));

        $applied = $lab->drainProjection();

        self::assertSame(1, $applied);
        self::assertSame('confirmed', $lab->projection->tripStatus($result->bookingId));
        self::assertSame(3, $lab->projectedAvailability(ResourceType::Flight, 'f1'));
    }

    private function trip(): \ArchitectureDecisionLab\Domain\TripRequest
    {
        return new \ArchitectureDecisionLab\Domain\TripRequest('t1', 'f1', 'h1', 'c1');
    }
}
