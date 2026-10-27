<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Tests\Documentation;

use PHPUnit\Framework\TestCase;

/**
 * Documentation is part of the contract, not an afterthought. These tests do
 * not check prose quality; they check that the files exist, the links resolve,
 * the seven chapters are covered and no document sends a reader to a moving
 * branch.
 */
final class DocumentationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRequiredDocumentsExistAndAreNotStubs(): void
    {
        $documents = [
            'README.md',
            'docs/ARCHITECTURE.md',
            'docs/DECISIONS.md',
            'docs/BENCHMARKS.md',
            'docs/COMPLEXITY.md',
            'docs/PHASES.md',
        ];

        foreach ($documents as $document) {
            $text = $this->read($document);
            self::assertGreaterThan(500, strlen($text), $document . ' is too short to be useful');
        }
    }

    public function testReadmeLocalLinksResolve(): void
    {
        $readme = $this->read('README.md');
        preg_match_all('/\]\((?P<path>[^)#\s]+)(?:#[^)]*)?\)/', $readme, $matches);

        /** @var list<string> $paths */
        $paths = $matches['path'];
        self::assertNotSame([], $paths);

        foreach ($paths as $path) {
            if (str_contains($path, '://') || str_starts_with($path, 'mailto:')) {
                continue;
            }

            self::assertFileExists($this->root . '/' . $path, 'README link does not resolve: ' . $path);
        }
    }

    public function testEveryChapterIsNamedInTheReadmeAndTheJournal(): void
    {
        $chapters = [
            'topology:deployment',
            'processing:sync-async',
            'storage:sql-document',
            'database:shared-per-service',
            'outbox:delivery',
            'transaction:saga-2pc',
            'consistency:projection-lag',
        ];

        $readme = $this->read('README.md');
        $phases = $this->read('docs/PHASES.md');

        foreach ($chapters as $chapter) {
            self::assertStringContainsString($chapter, $readme, 'README is missing ' . $chapter);
            self::assertStringContainsString($chapter, $phases, 'PHASES is missing ' . $chapter);
        }
    }

    public function testNoDocumentLinksToAMovingBranch(): void
    {
        $documents = [
            'README.md',
            'docs/ARCHITECTURE.md',
            'docs/DECISIONS.md',
            'docs/BENCHMARKS.md',
            'docs/COMPLEXITY.md',
            'docs/PHASES.md',
        ];

        foreach ($documents as $document) {
            $text = $this->read($document);
            self::assertStringNotContainsString('/blob/master/', $text, $document);
            self::assertStringNotContainsString('/tree/master/', $text, $document);
            self::assertStringNotContainsString('raw.githubusercontent.com/Researcher86/architecture-decision-lab/master', $text, $document);
        }
    }

    public function testReadmePinsTheRelease(): void
    {
        $readme = $this->read('README.md');

        self::assertStringContainsString(
            'https://github.com/Researcher86/architecture-decision-lab/releases/tag/v1.0.0',
            $readme,
        );
        self::assertStringContainsString('v1.0.0', $readme);
    }

    public function testBenchmarkDocumentCarriesTheEnvironmentAndAllSuites(): void
    {
        $benchmarks = $this->read('docs/BENCHMARKS.md');

        foreach ([
            'PHP 8.5.11',
            'Linux 7.0.14-linuxkit',
            'OPcache off, JIT off, Xdebug off',
            'git 87bc2f0',
            'five repetitions',
        ] as $marker) {
            self::assertStringContainsString($marker, $benchmarks, 'BENCHMARKS is missing: ' . $marker);
        }

        foreach ([
            '01-topology',
            '02-processing',
            '03-storage',
            '04-database',
            '05-outbox',
            '06-transaction',
            '07-consistency',
        ] as $suite) {
            self::assertStringContainsString($suite, $benchmarks, 'BENCHMARKS is missing suite ' . $suite);
        }
    }

    public function testBenchmarkDocumentStatesWhatIsNotMeasured(): void
    {
        $benchmarks = $this->read('docs/BENCHMARKS.md');

        self::assertStringContainsString('What is deliberately not measured', $benchmarks);
        self::assertStringContainsString('Real network latency', $benchmarks);
        self::assertStringContainsString('Process crashes', $benchmarks);
        self::assertStringContainsString('not a verdict on MongoDB', $benchmarks);
    }

    public function testComplexityDocumentKeepsCountersAndLimitsTogether(): void
    {
        $complexity = $this->read('docs/COMPLEXITY.md');

        foreach ([
            '1 + 3N',
            'Documents scanned',
            'twoPhaseHoldsGauge',
            'array_shift',
            'O(E²)',
            'What the counters do not prove',
        ] as $marker) {
            self::assertStringContainsString($marker, $complexity, 'COMPLEXITY is missing: ' . $marker);
        }
    }

    public function testDecisionRecordHasDatedEntries(): void
    {
        $decisions = $this->read('docs/DECISIONS.md');
        $count = preg_match_all('/^## \d{4}-\d{2}-\d{2} — /m', $decisions);

        self::assertIsInt($count);
        self::assertGreaterThanOrEqual(10, $count, 'DECISIONS needs dated, numbered entries');
    }

    public function testBuildJournalHasEveryPhaseAndACommitMap(): void
    {
        $phases = $this->read('docs/PHASES.md');

        for ($phase = 0; $phase <= 9; ++$phase) {
            self::assertStringContainsString('Phase ' . $phase, $phases, 'PHASES is missing phase ' . $phase);
        }

        self::assertStringContainsString('Status legend', $phases);
        self::assertStringContainsString('Commit map', $phases);
        self::assertStringContainsString('87bc2f0', $phases);
        self::assertStringContainsString('scenario passed', $phases);
    }

    public function testReadmeQuotesTheScenarioOutcomeAndTheLimits(): void
    {
        $readme = $this->read('README.md');

        foreach ([
            '1 confirmed',
            '1 cancelled',
            '4 compensation',
            '2 pending',
            'scenario passed',
            'Not implemented',
            'not MongoDB',
        ] as $marker) {
            self::assertStringContainsString($marker, $readme, 'README is missing: ' . $marker);
        }
    }

    private function read(string $relative): string
    {
        $path = $this->root . '/' . $relative;
        self::assertFileExists($path);

        $text = file_get_contents($path);

        if (!is_string($text)) {
            self::fail('Cannot read ' . $path);
        }

        return $text;
    }
}
