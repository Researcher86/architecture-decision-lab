<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Experiment;

use RuntimeException;

/**
 * Discovers every experiments/<topic>/<name>.php file. A file returns an
 * Experiment descriptor; there is no central list to drift out of date.
 */
final class Registry
{
    /** @var array<string, Experiment>|null */
    private ?array $experiments = null;

    public function __construct(
        private readonly string $directory,
    ) {
    }

    /**
     * @return array<string, Experiment>
     */
    public function all(): array
    {
        if ($this->experiments !== null) {
            return $this->experiments;
        }

        $experiments = [];

        foreach (glob($this->directory . '/*/*.php') ?: [] as $path) {
            $experiment = require $path;

            if (!$experiment instanceof Experiment) {
                throw new RuntimeException(sprintf('%s did not return an Experiment', $path));
            }

            if (isset($experiments[$experiment->name])) {
                throw new RuntimeException(sprintf('Two experiments are called %s', $experiment->name));
            }

            $experiments[$experiment->name] = $experiment;
        }

        ksort($experiments);

        return $this->experiments = $experiments;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function get(string $name): Experiment
    {
        return $this->all()[$name] ?? throw new RuntimeException(sprintf('Unknown experiment: %s', $name));
    }
}
