<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Integration;

use ArchitectureDecisionLab\Integration\ScenarioOptions;
use ArchitectureDecisionLab\Integration\TripBookingScenario;
use ArchitectureDecisionLab\Tests\Support\LabTestCase;

final class TripBookingScenarioTest extends LabTestCase
{
    public function testCompensatingScenarioPassesEveryInvariant(): void
    {
        $report = TripBookingScenario::run(new ScenarioOptions(
            mode: 'compensate',
            seed: 'test',
            databaseDirectory: $this->directory,
        ));

        self::assertTrue($report->passed);
        self::assertSame([], $report->invariants);
        self::assertSame(2, $report->summary['bookings']);
        self::assertSame(1, $report->summary['confirmed']);
        self::assertSame(1, $report->summary['cancelled']);
        self::assertSame(4, $report->summary['compensation_attempts']);
        self::assertSame(2, $report->summary['outbox_delivered']);
        self::assertSame(2, $report->summary['projection_stale_reads']);
        self::assertSame(3, $report->summary['flight_available_source']);
        self::assertSame(3, $report->summary['flight_available_projected']);
        self::assertStringContainsString('cancelled', implode("\n", $report->timeline));
        self::assertSame(1, $report->counters['failuresInjected'] ?? 0);
        self::assertSame(4, $report->counters['compensations'] ?? 0);
    }

    public function testSuccessScenarioProjectsOneConfirmedTrip(): void
    {
        $report = TripBookingScenario::run(new ScenarioOptions(
            mode: 'success',
            seed: 'test',
            databaseDirectory: $this->directory,
        ));

        self::assertTrue($report->passed);
        self::assertSame(1, $report->summary['bookings']);
        self::assertSame(1, $report->summary['confirmed']);
        self::assertSame(1, $report->summary['outbox_delivered']);
        self::assertSame(1, $report->summary['projection_trips']);
        self::assertSame(0, $report->counters['failuresInjected'] ?? 0);
    }

    public function testUnknownModeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScenarioOptions(mode: 'sometimes');
    }
}
