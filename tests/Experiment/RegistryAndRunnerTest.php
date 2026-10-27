<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Experiment;

use ArchitectureDecisionLab\Experiment\ExperimentRunner;
use ArchitectureDecisionLab\Experiment\Options;
use ArchitectureDecisionLab\Experiment\Output;
use ArchitectureDecisionLab\Experiment\Registry;
use PHPUnit\Framework\TestCase;

final class RegistryAndRunnerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/adl-experiments-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/topic', 0o777, true);

        file_put_contents($this->directory . '/topic/demo.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use ArchitectureDecisionLab\Experiment\Experiment;
            use ArchitectureDecisionLab\Experiment\Options;
            use ArchitectureDecisionLab\Experiment\Output;

            return new Experiment(
                name: 'demo:smoke',
                description: 'a demo experiment',
                supports: ['bookings'],
                run: static function (Options $options, Output $output): void {
                    $output->line('hello');
                    $output->measure('bookings', $options->int('bookings', 1));
                },
            );
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*/*.php') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->directory . '/topic');
        @rmdir($this->directory);
    }

    public function testDiscoversExperiments(): void
    {
        $registry = new Registry($this->directory);

        self::assertTrue($registry->has('demo:smoke'));
        self::assertSame('a demo experiment', $registry->get('demo:smoke')->description);
    }

    public function testRunsAnExperimentAndRecordsMeasurements(): void
    {
        $registry = new Registry($this->directory);
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            self::fail('cannot open memory stream');
        }

        $output = new Output($stream);
        $result = new ExperimentRunner($registry)->run(
            'demo:smoke',
            Options::fromStrings(['bookings' => '7']),
            $output,
        );

        rewind($stream);
        self::assertSame("hello\n", stream_get_contents($stream));
        self::assertSame(7, $result->measurements['bookings']);

        fclose($stream);
    }

    public function testRefusesAnOptionTheExperimentDoesNotSupport(): void
    {
        $registry = new Registry($this->directory);

        $this->expectException(\InvalidArgumentException::class);
        new ExperimentRunner($registry)->run(
            'demo:smoke',
            Options::fromStrings(['travelers' => '3']),
            new Output(),
        );
    }

    public function testQuietOutputSuppressesProseButKeepsMeasurements(): void
    {
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            self::fail('cannot open memory stream');
        }

        $output = new Output($stream, quiet: true);
        $output->line('hidden');
        $output->measure('x', 1);

        rewind($stream);
        self::assertSame('', stream_get_contents($stream));
        self::assertSame(['x' => 1], $output->measurements());

        fclose($stream);
    }
}
