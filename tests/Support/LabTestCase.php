<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Support;

use ArchitectureDecisionLab\Foundation\FaultInjector;
use ArchitectureDecisionLab\Instrumentation\Counters;
use ArchitectureDecisionLab\Integration\Lab;
use ArchitectureDecisionLab\Integration\LabConfig;
use ArchitectureDecisionLab\Integration\LabFactory;
use PHPUnit\Framework\TestCase;

/**
 * A laboratory in a throw-away directory. Tests never share a database, so a
 * counter or a row from one test cannot leak into another.
 */
abstract class LabTestCase extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/adl-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->delete($this->directory);
    }

    protected function config(mixed ...$overrides): LabConfig
    {
        $defaults = [
            'databaseDirectory' => $this->directory,
            'seed' => 'test',
        ];

        /** @var array<string, mixed> $values */
        $values = array_merge($defaults, $overrides);

        return new LabConfig(...$values);
    }
    protected function lab(
        ?LabConfig $config = null,
        ?FaultInjector $faults = null,
        ?Counters $counters = null,
    ): Lab {
        return LabFactory::create($config ?? $this->config(), $faults, $counters);
    }

    private function delete(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;

            if (is_dir($child)) {
                $this->delete($child);

                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }
}
