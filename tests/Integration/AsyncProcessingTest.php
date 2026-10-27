<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Integration;

use ArchitectureDecisionLab\Domain\BookingStatus;
use ArchitectureDecisionLab\Domain\ProcessingMode;
use ArchitectureDecisionLab\Domain\TripRequest;
use ArchitectureDecisionLab\Tests\Support\LabTestCase;

final class AsyncProcessingTest extends LabTestCase
{
    public function testBoundedQueueRejectsWorkInsteadOfGrowingWithoutLimit(): void
    {
        $lab = $this->lab($this->config(
            processing: ProcessingMode::Async,
            queueLimit: 2,
        ));
        $lab->seed('f1', 'h1', 'c1', capacity: 10);

        $accepted = 0;
        $rejected = 0;

        for ($index = 0; $index < 4; ++$index) {
            $result = $lab->book(new TripRequest('t' . $index, 'f1', 'h1', 'c1'), $index);

            if ($result->accepted) {
                ++$accepted;
            } else {
                ++$rejected;
            }
        }

        self::assertSame(2, $accepted);
        self::assertSame(2, $rejected);
        self::assertSame(2, $lab->queue->size());
        self::assertSame(2, $lab->counters->get('queueRejections'));
    }

    public function testWorkerDrainsTheQueueAndReportsCompletionLatency(): void
    {
        $lab = $this->lab($this->config(
            processing: ProcessingMode::Async,
            queueLimit: 10,
        ));
        $lab->seed('f1', 'h1', 'c1', capacity: 10);

        $submitted = $lab->book(new TripRequest('t1', 'f1', 'h1', 'c1'), 0);

        self::assertSame(BookingStatus::Pending, $submitted->status);
        self::assertTrue($submitted->accepted);
        self::assertSame(0, $submitted->completionLatencyMs);

        $results = $lab->drain(workers: 1, batchSize: 10);

        self::assertCount(1, $results);
        self::assertSame(BookingStatus::Confirmed, $results[0]->status);
        self::assertGreaterThan($results[0]->clientLatencyMs, $results[0]->completionLatencyMs);
        self::assertSame(0, $lab->queue->size());
        self::assertSame(1, $lab->counters->get('jobsProcessed'));
    }

    public function testABusinessFailureIsCancelledRatherThanRetried(): void
    {
        $counters = new \ArchitectureDecisionLab\Instrumentation\Counters();
        $faults = new \ArchitectureDecisionLab\Foundation\FaultInjector(
            step: 'payment.authorize',
            counters: $counters,
        );
        $lab = $this->lab(
            $this->config(
                processing: ProcessingMode::Async,
                queueLimit: 10,
                transaction: \ArchitectureDecisionLab\Domain\TransactionMode::Saga,
            ),
            $faults,
            $counters,
        );
        $lab->seed('f1', 'h1', 'c1', capacity: 10);
        $lab->book(new TripRequest('t1', 'f1', 'h1', 'c1'), 0);

        $results = $lab->drain(workers: 1, batchSize: 10);

        self::assertCount(1, $results);
        self::assertSame(BookingStatus::Cancelled, $results[0]->status);
        self::assertSame(0, $lab->counters->get('jobsRetried'));
        self::assertSame(0, $lab->counters->get('jobsFailed'));
    }
}
