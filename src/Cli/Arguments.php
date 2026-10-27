<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Cli;

use InvalidArgumentException;

/**
 * A small command line: one command plus --name=value options.
 *
 * Both `--name=value` and `--name value` are accepted. A bare token after the
 * command is refused instead of becoming a stray positional argument, because
 * a typo in an experiment name or option should not silently change the run.
 */
final readonly class Arguments
{
    /**
     * @param array<string, string> $options
     */
    private function __construct(
        public ?string $command,
        private array $options,
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public static function parse(array $argv): self
    {
        $command = null;
        $options = [];
        $count = count($argv);

        for ($i = 0; $i < $count; ++$i) {
            $argument = $argv[$i];

            if (!str_starts_with($argument, '--')) {
                if ($command !== null) {
                    throw new InvalidArgumentException(sprintf(
                        'Unexpected argument "%s"; only one command is allowed',
                        $argument,
                    ));
                }

                $command = $argument;

                continue;
            }

            $option = substr($argument, 2);
            $equals = strpos($option, '=');

            if ($equals !== false) {
                $name = substr($option, 0, $equals);
                $value = substr($option, $equals + 1);
            } else {
                $name = $option;
                $value = '';

                if ($i + 1 < $count && !str_starts_with($argv[$i + 1], '--')) {
                    $value = $argv[++$i];
                }
            }

            if ($name === '') {
                throw new InvalidArgumentException('An option cannot have an empty name');
            }

            if (array_key_exists($name, $options)) {
                throw new InvalidArgumentException(sprintf('Option --%s is repeated', $name));
            }

            $options[$name] = $value;
        }

        return new self($command, $options);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function string(string $name, ?string $default = null): ?string
    {
        if (!array_key_exists($name, $this->options)) {
            return $default;
        }

        return $this->options[$name];
    }

    public function positiveInt(string $name, int $default): int
    {
        if (!array_key_exists($name, $this->options)) {
            return $default;
        }

        $value = $this->options[$name];

        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
            throw new InvalidArgumentException(sprintf('--%s must be a positive integer, got "%s"', $name, $value));
        }

        return (int) $value;
    }

    public function nonNegativeInt(string $name, int $default): int
    {
        if (!array_key_exists($name, $this->options)) {
            return $default;
        }

        $value = $this->options[$name];

        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('--%s must be a non-negative integer, got "%s"', $name, $value));
        }

        return (int) $value;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->options;
    }

    /**
     * @return array<string, string>
     */
    public function except(string ...$names): array
    {
        return array_diff_key($this->options, array_flip($names));
    }
}
