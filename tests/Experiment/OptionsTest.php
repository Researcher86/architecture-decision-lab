<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Experiment;

use ArchitectureDecisionLab\Experiment\Options;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    public function testParsesTypedValues(): void
    {
        $options = Options::fromStrings([
            'seed' => 'book',
            'bookings' => '12',
            'lag' => '0',
        ]);

        self::assertSame('book', $options->string('seed', 'x'));
        self::assertSame(12, $options->int('bookings', 1));
        self::assertSame(0, $options->int('lag', 99));
    }

    public function testRefusesUnknownOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Options::fromStrings(['nope' => '1']);
    }

    public function testRefusesInvalidInteger(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Options::fromStrings(['bookings' => 'ten']);
    }

    public function testRefusesEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Options::fromStrings(['seed' => '  ']);
    }

    public function testToArrayKeepsTypes(): void
    {
        $options = Options::fromStrings(['bookings' => '5', 'seed' => 'book']);

        self::assertSame(['bookings' => 5, 'seed' => 'book'], $options->toArray());
    }
}
