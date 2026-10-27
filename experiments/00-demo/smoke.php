<?php

declare(strict_types=1);

use ArchitectureDecisionLab\Experiment\Experiment;
use ArchitectureDecisionLab\Experiment\Options;
use ArchitectureDecisionLab\Experiment\Output;
use ArchitectureDecisionLab\Foundation\ByteFormatter;
use ArchitectureDecisionLab\Instrumentation\Measure;

return new Experiment(
    name: 'demo:smoke',
    description: 'show the harness: counters, memory, prose and measurements',
    supports: ['iterations', 'seed'],
    run: static function (Options $options, Output $output): void {
        $iterations = $options->int('iterations', 100_000);
        $seed = $options->string('seed', 'demo');
        $measure = new Measure();

        $output->heading('Demo smoke', sprintf('seed=%s iterations=%d', $seed, $iterations));
        $measure->start();

        $sum = 0;

        for ($i = 0; $i < $iterations; ++$i) {
            if (($i & 1) === 0) {
                $measure->counters->increment('even');
            }

            $sum += $i;
        }

        $result = $measure->stop();

        $output->line(sprintf('sum=%d', $sum));
        $output->line(sprintf('elapsed=%.3f ms', $result['seconds'] * 1000));
        $output->line(sprintf('counters: even=%d', $result['counters']['even'] ?? 0));
        $output->line(sprintf('memory delta: %s', ByteFormatter::format($result['memory']['usage_delta'])));
        $output->line();
        $output->line('This file is the harness smoke test; the chapters are under 01-07.');

        $output->measure('iterations', $iterations);
        $output->measure('sum', $sum);
        $output->measure('even', $result['counters']['even'] ?? 0);
        $output->measure('elapsed_seconds', $result['seconds']);
        $output->measure('memory_delta_bytes', $result['memory']['usage_delta']);
    },
);
