<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Foundation;

use ArchitectureDecisionLab\Foundation\DeterministicRandom;
use PHPUnit\Framework\TestCase;

final class DeterministicRandomTest extends TestCase
{
    public function testSameSeedProducesSameSequence(): void
    {
        $a = new DeterministicRandom('book');
        $b = new DeterministicRandom('book');

        for ($i = 0; $i < 20; ++$i) {
            self::assertSame($a->next(), $b->next());
        }
    }

    public function testDifferentSeedsDiverge(): void
    {
        $a = new DeterministicRandom('book');
        $b = new DeterministicRandom('other');

        self::assertNotSame($a->next(), $b->next());
    }

    public function testNextIntStaysInRange(): void
    {
        $random = new DeterministicRandom('range');

        for ($i = 0; $i < 1000; ++$i) {
            $value = $random->nextInt(7);

            self::assertGreaterThanOrEqual(0, $value);
            self::assertLessThan(7, $value);
        }
    }

    public function testNextIntRefusesEmptyRange(): void
    {
        $random = new DeterministicRandom('range');

        $this->expectException(\InvalidArgumentException::class);
        $random->nextInt(0);
    }

    public function testChanceIsDeterministic(): void
    {
        $a = new DeterministicRandom('chance');
        $b = new DeterministicRandom('chance');

        for ($i = 0; $i < 100; ++$i) {
            self::assertSame($a->chance(1, 4), $b->chance(1, 4));
        }
    }
}
