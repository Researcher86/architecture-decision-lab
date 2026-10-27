<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Experiment;

use InvalidArgumentException;

/**
 * Runs one experiment and enforces that it only receives options it declared.
 */
final readonly class ExperimentRunner
{
    public function __construct(
        private Registry $registry,
    ) {
    }

    public function run(string $name, Options $options, Output $output): ExperimentResult
    {
        $experiment = $this->registry->get($name);
        $unsupported = array_diff($options->names(), $experiment->supports);

        if ($unsupported !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s does not use --%s. It understands: %s',
                $name,
                implode(', --', $unsupported),
                $experiment->supports === [] ? 'no options' : '--' . implode(', --', $experiment->supports),
            ));
        }

        $start = hrtime(true);
        ($experiment->run)($options, $output);

        return new ExperimentResult(
            name: $experiment->name,
            description: $experiment->description,
            options: $options->toArray(),
            measurements: $output->measurements(),
            elapsedSeconds: (hrtime(true) - $start) / 1e9,
        );
    }
}
