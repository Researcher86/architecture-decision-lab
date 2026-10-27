<?php

declare(strict_types=1);

use ArchitectureDecisionLab\Benchmark\Benchmark;

return [
    new Benchmark(
        name: 'demo.strlen',
        iterations: 200_000,
        run: static function (): int {
            return strlen('architecture-decision-lab');
        },
    ),
    new Benchmark(
        name: 'demo.array_append',
        iterations: 20_000,
        run: static function (): void {
            $values = [];

            for ($i = 0; $i < 100; ++$i) {
                $values[] = $i;
            }
        },
    ),
];
