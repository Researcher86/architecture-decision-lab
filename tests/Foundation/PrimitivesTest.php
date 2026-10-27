<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Foundation;

use ArchitectureDecisionLab\Foundation\ByteFormatter;
use ArchitectureDecisionLab\Foundation\Money;
use ArchitectureDecisionLab\Foundation\SequentialIdGenerator;
use ArchitectureDecisionLab\Foundation\VirtualClock;
use PHPUnit\Framework\TestCase;

final class PrimitivesTest extends TestCase
{
    public function testMoneyIsIntegerCents(): void
    {
        $money = Money::fromCents(1299);

        self::assertSame('12.99', $money->format());
        self::assertSame(2598, $money->add($money)->cents);
        self::assertSame(3897, $money->multiply(3)->cents);
    }

    public function testMoneyRefusesNegativeAmounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Money(-1);
    }

    public function testClockMovesForwardOnly(): void
    {
        $clock = new VirtualClock(10);
        $clock->advance(5);

        self::assertSame(15, $clock->now());

        $this->expectException(\InvalidArgumentException::class);
        $clock->set(14);
    }

    public function testIdsAreSequentialPerPrefix(): void
    {
        $ids = new SequentialIdGenerator();

        self::assertSame('trip-0001', $ids->next('trip'));
        self::assertSame('flight-0001', $ids->next('flight'));
        self::assertSame('trip-0002', $ids->next('trip'));
    }

    public function testByteFormatterUsesBinaryUnits(): void
    {
        self::assertSame('512 B', ByteFormatter::format(512));
        self::assertSame('1.0 KiB', ByteFormatter::format(1024));
        self::assertSame('1.5 MiB', ByteFormatter::format(1024 * 1024 + 512 * 1024));
    }
}
