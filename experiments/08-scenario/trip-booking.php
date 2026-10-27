<?php

declare(strict_types=1);

use ArchitectureDecisionLab\Experiment\Experiment;
use ArchitectureDecisionLab\Experiment\Options;
use ArchitectureDecisionLab\Experiment\Output;
use ArchitectureDecisionLab\Integration\ScenarioOptions;
use ArchitectureDecisionLab\Integration\TripBookingScenario;

return new Experiment(
    name: 'scenario:trip-booking',
    description: 'the MVP: book a package, fail payment, compensate and project the outcome',
    supports: ['mode', 'seed', 'lag'],
    run: static function (Options $options, Output $output): void {
        $report = TripBookingScenario::run(new ScenarioOptions(
            mode: $options->string('mode', 'compensate'),
            seed: $options->string('seed', 'trip-booking'),
            projectionLagMs: $options->int('lag', 50),
        ));

        $output->heading('Trip booking scenario', sprintf('mode=%s seed=%s', $report->mode, $report->config->seed));
        $output->line('decisions:');
        $output->line(sprintf(
            '  shape=%s processing=%s storage=%s database=%s',
            $report->config->shape->value,
            $report->config->processing->value,
            $report->config->storage->value,
            $report->config->database->value,
        ));
        $output->line(sprintf(
            '  delivery=%s transaction=%s consistency=%s projection_lag_ms=%d',
            $report->config->delivery->value,
            $report->config->transaction->value,
            $report->config->consistency->value,
            $report->config->projectionLagMs,
        ));
        $output->line();
        $output->line('timeline:');

        foreach ($report->timeline as $line) {
            $output->line('  ' . $line);
        }

        $output->line();
        $output->line('summary:');

        foreach ($report->summary as $name => $value) {
            $output->line(sprintf('  %-32s %s', $name, (string) $value));
        }

        $output->line();
        $output->line('invariants:');

        if ($report->invariants === []) {
            $output->line('  all checks passed');
        } else {
            foreach ($report->invariants as $violation) {
                $output->line('  VIOLATION: ' . $violation);
            }
        }

        $output->line();
        $output->line('counters:');

        foreach ($report->counters as $name => $value) {
            $output->line(sprintf('  %-32s %d', $name, $value));
        }

        $output->line();
        $output->line($report->passed ? 'scenario passed' : 'scenario failed');

        foreach ($report->summary as $name => $value) {
            $output->measure($name, $value);
        }

        $output->measure('invariants_passed', $report->passed);
        $output->measure('failures_injected', $report->counters['failuresInjected'] ?? 0);
        $output->measure('compensations', $report->counters['compensations'] ?? 0);
        $output->measure('outbox_published', $report->counters['outboxPublished'] ?? 0);
    },
);
