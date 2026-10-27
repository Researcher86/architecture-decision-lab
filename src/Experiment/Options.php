<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Experiment;

use InvalidArgumentException;

/**
 * Validated options shared by every experiment.
 *
 * The set is closed: `--shape` means the same thing everywhere, and an option
 * an experiment does not read is refused by the runner instead of being
 * silently ignored. That is how a report cannot claim a configuration it did
 * not run.
 */
final readonly class Options
{
    /** @var array<string, string> */
    public const KNOWN = [
        'seed' => 'seed for a reproducible run',
        'bookings' => 'number of trips to book',
        'travelers' => 'number of distinct travellers',
        'capacity' => 'initial seats, rooms or vehicles',
        'fail-every' => 'fail every Nth booking, 0 disables',
        'failure-step' => 'named step at which to inject a failure',
        'failure-after' => '1 injects after the effect, 0 before it',
        'shape' => 'monolith, modular-monolith or microservices',
        'processing' => 'sync or async',
        'storage' => 'sql or document',
        'database' => 'shared or per-service',
        'delivery' => 'dual-write or outbox',
        'transaction' => 'saga or two-phase-commit',
        'consistency' => 'strong or eventual',
        'queue-limit' => 'maximum queued jobs before back-pressure',
        'events' => 'number of events in a delivery run',
        'reads' => 'number of reads in a consistency run',
        'lag' => 'projection lag in logical milliseconds',
        'iterations' => 'times to repeat a measured operation',
        'repetitions' => 'timed repetitions in a benchmark',
        'workers' => 'number of asynchronous workers',
        'batch-size' => 'jobs processed by one worker tick',
        'runs' => 'number of independent runs',
        'mode' => 'named scenario mode',
        'crash' => 'crash injection point',
        'query' => 'query shape under test',
        'transport' => 'direct call or modelled remote call',
    ];

    /** @var list<string> */
    private const INTEGER_OPTIONS = [
        'bookings',
        'travelers',
        'capacity',
        'fail-every',
        'failure-after',
        'queue-limit',
        'events',
        'reads',
        'lag',
        'iterations',
        'repetitions',
        'workers',
        'batch-size',
        'runs',
    ];

    /**
     * @param array<string, int|string> $values
     */
    private function __construct(
        private array $values,
    ) {
    }

    /**
     * @param array<string, string> $raw
     */
    public static function fromStrings(array $raw): self
    {
        $values = [];

        foreach ($raw as $name => $value) {
            if (!array_key_exists($name, self::KNOWN)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown option --%s. Known options: %s',
                    $name,
                    implode(', ', array_keys(self::KNOWN)),
                ));
            }

            $values[$name] = in_array($name, self::INTEGER_OPTIONS, true)
                ? self::parseInteger($name, $value)
                : self::parseString($name, $value);
        }

        return new self($values);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function int(string $name, int $default): int
    {
        $value = $this->values[$name] ?? $default;

        return is_int($value) ? $value : (int) $value;
    }

    public function string(string $name, string $default): string
    {
        $value = $this->values[$name] ?? $default;

        return is_string($value) ? $value : (string) $value;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->values);
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    private static function parseInteger(string $name, string $value): int
    {
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '--%s must be an integer, got "%s"',
                $name,
                $value,
            ));
        }

        return (int) $value;
    }

    private static function parseString(string $name, string $value): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('--%s cannot be empty', $name));
        }

        return $value;
    }
}
