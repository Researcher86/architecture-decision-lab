<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Benchmark;

use ArchitectureDecisionLab\Benchmark\BenchmarkRunner;
use ArchitectureDecisionLab\Benchmark\Environment;
use ArchitectureDecisionLab\Benchmark\Timings;
use PHPUnit\Framework\TestCase;

final class BenchmarkHarnessTest extends TestCase
{
    public function testTimingsUseNearestRank(): void
    {
        $timings = Timings::of([1.0, 2.0, 3.0, 4.0, 5.0]);

        self::assertSame(1.0, $timings->min);
        self::assertSame(5.0, $timings->max);
        self::assertSame(3.0, $timings->median);
        self::assertSame(5.0, $timings->p95);
        self::assertSame(3.0, $timings->mean);
    }

    public function testRunnerRunsWarmupsAndRepetitions(): void
    {
        $calls = 0;
        $runner = new BenchmarkRunner(repetitions: 3, warmups: 1);
        $result = $runner->run('count', static function () use (&$calls): void {
            ++$calls;
        }, 4);

        self::assertSame(16, $calls);
        self::assertSame(4, $result->iterations);
        self::assertSame(3, $result->repetitions);
        self::assertGreaterThan(0.0, $result->operationsPerSecond());
    }

    public function testRunnerRefusesInvalidSettings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BenchmarkRunner(repetitions: 0);
    }

    public function testEnvironmentCarriesTheCommitAndRuntime(): void
    {
        $environment = Environment::detect();

        self::assertSame(PHP_VERSION, $environment->phpVersion);
        self::assertNotSame('', $environment->commit);
        self::assertArrayHasKey('php_version', $environment->toArray());
    }
}
