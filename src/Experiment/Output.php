<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Experiment;

/**
 * Where an experiment writes prose and records named measurements. Prose is
 * streamed so a slow run does not look hung; measurements are what JSON
 * output carries. Quiet mode suppresses prose only.
 */
final class Output
{
    /** @var array<string, int|float|string|bool|null> */
    private array $measurements = [];

    /**
     * @param resource $stream
     */
    public function __construct(
        private readonly mixed $stream = STDOUT,
        private readonly bool $quiet = false,
    ) {
    }

    public function line(string $line = ''): void
    {
        $this->write($line . "\n");
    }

    public function write(string $text): void
    {
        if (!$this->quiet) {
            fwrite($this->stream, $text);
        }
    }

    public function printf(string $format, int|float|string ...$arguments): void
    {
        $this->write(sprintf($format, ...$arguments));
    }

    public function heading(string $title, string $description = ''): void
    {
        $this->line(sprintf('Experiment: %s', $title));

        if ($description !== '') {
            $this->line($description);
        }

        $this->line();
    }

    public function measure(string $name, int|float|string|bool|null $value): void
    {
        $this->measurements[$name] = $value;
    }

    /**
     * @param array<string, int|float|string|bool|null> $values
     */
    public function measureAll(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->measure($name, $value);
        }
    }

    /**
     * @return array<string, int|float|string|bool|null>
     */
    public function measurements(): array
    {
        return $this->measurements;
    }
}
