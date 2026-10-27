<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Integration;

use ArchitectureDecisionLab\Domain\DatabaseMode;
use ArchitectureDecisionLab\Domain\DeploymentShape;
use ArchitectureDecisionLab\Domain\TripRequest;
use ArchitectureDecisionLab\Tests\Support\LabTestCase;

final class TripReportTest extends LabTestCase
{
    public function testSharedDatabaseReportIsOneJoin(): void
    {
        $lab = $this->lab($this->config(
            shape: DeploymentShape::Microservices,
            database: DatabaseMode::Shared,
        ));
        $lab->seed('f1', 'h1', 'c1', capacity: 10);
        $lab->book(new TripRequest('t1', 'f1', 'h1', 'c1'));

        $lab->resetCounters();
        $summaries = $lab->tripReport->forTraveler('t1');

        self::assertSame('sql-join', $lab->tripReport->kind());
        self::assertCount(1, $summaries);
        self::assertSame(1, $lab->counters->get('sqlQueries'));
        self::assertSame(9, $summaries[0]->flightAvailable);
    }

    public function testPerServiceReportCallsEveryInventoryService(): void
    {
        $lab = $this->lab($this->config(
            shape: DeploymentShape::Microservices,
            database: DatabaseMode::PerService,
            transaction: \ArchitectureDecisionLab\Domain\TransactionMode::Saga,
        ));
        $lab->seed('f1', 'h1', 'c1', capacity: 10);
        $lab->book(new TripRequest('t1', 'f1', 'h1', 'c1'));

        $lab->resetCounters();
        $summaries = $lab->tripReport->forTraveler('t1');

        self::assertSame('service-calls', $lab->tripReport->kind());
        self::assertCount(1, $summaries);
        self::assertSame(3, $lab->counters->get('remoteCalls'));
        self::assertSame(9, $summaries[0]->flightAvailable);
    }
}
