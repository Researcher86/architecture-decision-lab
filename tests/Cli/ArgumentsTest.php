<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Cli;

use ArchitectureDecisionLab\Cli\Arguments;
use PHPUnit\Framework\TestCase;

final class ArgumentsTest extends TestCase
{
    public function testParsesCommandAndBothOptionForms(): void
    {
        $arguments = Arguments::parse(['topology:deployment', '--shape=modular', '--bookings', '50']);

        self::assertSame('topology:deployment', $arguments->command);
        self::assertSame('modular', $arguments->string('shape'));
        self::assertSame('50', $arguments->string('bookings'));
    }

    public function testEmptyCommandMeansListMode(): void
    {
        $arguments = Arguments::parse([]);

        self::assertNull($arguments->command);
    }

    public function testRefusesASecondBareArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Arguments::parse(['one', 'two']);
    }

    public function testRefusesDuplicateOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Arguments::parse(['--seed=a', '--seed=b']);
    }

    public function testPositiveIntValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Arguments::parse(['--bookings=0'])->positiveInt('bookings', 10);
    }

    public function testExceptRemovesReservedOptions(): void
    {
        $arguments = Arguments::parse(['--format=json', '--seed=book']);
        $rest = $arguments->except('format');

        self::assertSame(['seed' => 'book'], $rest);
    }
}
