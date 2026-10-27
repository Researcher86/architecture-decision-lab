# Architecture Decision Lab — How It Was Built

This is the build journal. Each phase states its goal, what made it real, the
definition of done, and the evidence that holds it. Work happens in the project
container; a phase is complete only after `make test`, `make analyse` and
`make format-check` pass there.

Status legend: ✅ complete, 🚧 in progress, ⏳ planned.

---

## 1. Where this project fits

```text
engineering-lab/                     # workspace
├── book/                            # git: Researcher86/engineering-lab-book
│   ├── README.md                    # series table of contents
│   └── architecture-decision-lab/   # seven Russian chapters
├── architecture-decision-lab/       # this project
│   ├── src/                         # domain, variants, harness, scenario
│   ├── experiments/                 # runnable questions
│   ├── benchmarks/                  # measured suites
│   ├── tests/                       # unit, integration, scenario and docs tests
│   ├── docs/                        # engineering documentation (English)
│   └── var/                         # generated databases and results (gitignored)
├── architecture-lab/                # sibling: code structure and dependencies
└── algorithms-lab/                  # sibling: complexity and data structures
```

### Roles

| Component | Role |
|---|---|
| `architecture-decision-lab` | Executable source of truth: code, tests, measurements, English docs |
| `book/architecture-decision-lab` | Russian narrative, seven chapters, code pinned to `v1.0.0` |
| `book/php-systems-lab/tools/check_book.py` | Verifies code blocks and links, with `--expected-chapters` |
| `book/.github/workflows/book.yml` | Book CI for every lab in the series |
| `engineering-lab-book` | Umbrella book repository and published narrative |

### Contract with the ecosystem

1. **One domain, seven decisions.** The same trip-booking flow is used to
   examine deployment shape, processing mode, storage shape, database
   ownership, event delivery, transaction model and consistency.
2. **The book is written from the code.** Implementation, tests and
   measurements first; chapters quote real files and real output.
3. **Links are pinned.** Every book link targets `v1.0.0` or a full SHA, never
   a branch.
4. **Languages.** Code, comments, README and `docs/` are English. Book chapters
   are Russian.
5. **One command per experiment.** `make experiment ARGS="..."` is the
   interface the book can quote.
6. **The book is checked mechanically.** Seven chapters, closed fences, exact
   excerpts, valid line ranges, resolvable links.

---

## 2. The fixed chapter order

| # | Chapter | Core question | Experiment |
|---|---|---|---|
| 01 | Monolith vs Modular Monolith vs Microservices | what does a deployment boundary buy, and what does it cost? | `topology:deployment` |
| 02 | Sync vs Async Processing | when is a 202 honest and when is it hiding failure? | `processing:sync-async` |
| 03 | SQL vs NoSQL | which query shape and transaction boundary does the data need? | `storage:sql-document` |
| 04 | Database per Service | what happens when a join becomes a service call? | `database:shared-per-service` |
| 05 | Outbox Pattern | how does an event survive the gap between commit and publish? | `outbox:delivery` |
| 06 | Saga vs Distributed Transaction | atomicity, locks and compensation under failure | `transaction:saga-2pc` |
| 07 | Eventual Consistency | stale reads, convergence and read-your-writes | `consistency:projection-lag` |

The MVP scenario is `scenario:trip-booking`: a traveler books a package of
flight, hotel and car, payment is authorized, and a deterministic failure
forces either a rollback or a compensation. It is one command and one report.

---

## 3. Phase 0 — Context and plan ✅

- Read the draft, the process prompt and the `algorithms-lab` reference.
- Fix the trip-booking domain, the seven chapters and the MVP scenario.
- Record the stack and the non-goals.

**Definition of done:** the scope, chapter order, scenario and limitations are
written down; no code depends on an unstated requirement.

**Evidence:** this document and `docs/DECISIONS.md`.

## 4. Phase 1 — Scaffold and harness ✅

- `composer.json`, `Dockerfile`, `docker-compose.yml`, `Makefile`, PHPUnit,
  PHPStan, PHP-CS-Fixer, `.gitignore`, `.editorconfig`, CI workflow.
- Discoverable experiment and benchmark descriptors.
- Environment block: PHP, OS, architecture, CPU, memory limit, cgroup,
  OPcache, JIT, Xdebug, allocator, commit.
- Demo experiment and demo benchmark.

**Definition of done:** `make build`, `make install`, `make test`,
`make analyse`, `make format-check` are green before chapter code exists.

**Commit:** `efc3cca` (`chore: scaffold the Docker-first PHP project`).

## 5. Phase 2 — Domain, services, transactions and messaging ✅

- Booking aggregate, trip request, result and status types.
- Three inventory services with conditional capacity updates and idempotent
  reservations; payment authorization, capture and void.
- Direct, module and remote transports; deterministic fault injection at named
  steps, including failures after the effect.
- Local transaction, orchestrated saga, two-phase commit with holds and
  explicit recovery.
- Outbox, at-least-once dispatcher, idempotent projector, strong and eventual
  projection modes.
- SQL and document booking stores; SQL-join and service-call traveler reports.
- SQLite schema for shared and per-service databases.

**Definition of done:** the seven decisions are executable; invalid
combinations are rejected; failure injection changes durable state; tests cover
the transaction, outbox and projection boundaries.

**Evidence:** `make test` → 63 tests, 3,353 assertions; PHPStan level 8 clean;
PHP-CS-Fixer clean. See `var/results/` for the deterministic outputs.

**Commit:** `cc20e76` (`feat: model trip booking, transport, saga, outbox and projections`).

## 6. Phase 3 — MVP scenario ✅

- `TripBookingScenario` fixes the reference configuration: microservices,
  per-service database, saga, outbox, eventual projection.
- Two bookings: the first confirms; payment on the second fails before the
  effect; the saga compensates four steps.
- The outbox carries both outcomes; the projection is stale until drained.
- `make scenario` prints the timeline, summary, invariants and counters.
- `ScenarioOptions` allows `success` and `compensate` modes and a seed.

**Definition of done:** one command, one deterministic report, all invariants
pass; the scenario test asserts the real summary and counters.

**Evidence:**

```text
make scenario
t=9  booking trip-0001: confirmed after 8 ms, compensation attempts 0
t=26 booking trip-0002: cancelled after 16 ms, compensation attempts 4
t=26 outbox has 2 pending event(s); the projection still reports trip-0001 as absent
t=76 projection drained 2 event(s); booking trip-0001 is now confirmed
scenario passed
```

Summary: 2 bookings, 1 confirmed, 1 cancelled, 4 compensation attempts, 2 events
delivered, 2 stale reads, source and projection both 3.

**Commit:** `b37f303` (`feat: run the trip-booking scenario and traveler reports`).

## 7. Phase 4 — The seven chapter experiments ✅

- One experiment per chapter under `experiments/01-topology` …
  `experiments/07-consistency`, plus the scenario under `experiments/08-scenario`.
- Every experiment is discovered by the harness; there is no central registry.
- Every experiment declares the options it reads; the runner rejects unknown
  options.
- Output is deterministic in logical time and includes measurements suitable
  for JSON.

**Definition of done:** every experiment runs with `make experiment ARGS="..."`,
prints a table and prose, and the output is saved in `var/results/`.

**Evidence (real defaults):**

| Experiment | Key result |
|---|---|
| `topology:deployment` | 29/30 confirmed; microservices 3 compensations and 122 remote calls, 8.13 ms logical average |
| `processing:sync-async` | async submit 1.00 ms vs sync 0.00 ms; async completion 25.50 ms; bounded run accepts 10, rejects 40 |
| `storage:sql-document` | document point read 1 row, SQL 2 queries; document traveler query scans 1,000 documents, SQL reads 300 rows |
| `database:shared-per-service` | report 1 SQL query shared vs 61 per service; 60 remote calls, 3,840 request bytes |
| `outbox:delivery` | dual-write crash loses 1 event; outbox retries with 0 lost; duplicate absorbed |
| `transaction:saga-2pc` | saga 3–4 compensations; 2PC crash leaves 4 holds; recovery commits or aborts |
| `consistency:projection-lag` | 100/100 stale reads before drain, 0/100 after; 20 events, 50 ms lag |
| `scenario:trip-booking` | 2 bookings, 1 confirmed, 1 cancelled, 4 compensations, all invariants passed |

**Commit:** `448e003` (`feat: add the seven chapter experiments`).

## 8. Phase 5 — The seven benchmark suites ✅

- One suite per chapter under `benchmarks/01-topology.php` …
  `benchmarks/07-consistency.php`.
- Every report includes the environment block, commit SHA, median, p95, max,
  ops/sec and PHP allocator delta.
- The topology suite measures full bookings, not just a single reserve call, so
  it compares the configurations a team would actually deploy.
- Real outputs are stored in `var/benchmarks/` (gitignored).

**Definition of done:** every suite runs with `make benchmark ARGS="..."` and
the numbers are quoted with their commit and environment.

**Evidence (benchmark commit `87bc2f0`, five repetitions, one warm-up):**

| Benchmark | Median | Per operation | Reading |
|---|---:|---:|---|
| topology monolith booking | 645.366 ms | 1,291 µs | baseline |
| topology modular booking | 621.101 ms | 1,242 µs | same database and transaction; difference is noise |
| topology microservices booking | 804.615 ms | 1,609 µs | +24.6% vs monolith (bundled boundary, DB split and saga) |
| processing sync booking | 594.522 ms | 1,189 µs | baseline |
| processing async submit | 95.479 ms | 191 µs | 6.2× cheaper; queue grows |
| processing async roundtrip | 666.326 ms | 1,333 µs | +12.1% vs sync |
| storage SQL point read | 88.984 ms | 17.8 µs | two statements |
| storage document point read | 46.674 ms | 9.3 µs | one row |
| storage SQL traveler query | 84.343 ms | 84.3 µs | one join |
| storage document traveler query | 452.050 ms | 452.1 µs | 5.4× slower, full scan |
| database shared report | 40.018 ms | 40.0 µs | one join |
| database per-service report | 563.023 ms | 563.0 µs | 14.1× slower, N+1 |
| outbox dual write booking | 373.385 ms | 1,245 µs | synchronous publish |
| outbox outbox booking | 345.188 ms | 1,151 µs | difference is noise; delivery deferred |
| outbox booking + dispatch | 485.556 ms | 1,619 µs | +40.7% vs outbox write |
| transaction local booking | 370.805 ms | 1,236 µs | baseline |
| transaction saga booking | 477.649 ms | 1,592 µs | +28.8% |
| transaction 2PC booking | 610.844 ms | 2,036 µs | +64.7% vs local |
| consistency strong roundtrip | 551.688 ms | 1,839 µs | baseline |
| consistency eventual roundtrip | 527.824 ms | 1,759 µs | median difference is noise; p95 744.556 ms |

**Commit:** `87bc2f017532cd2576addc48b32cc5d3b158410f`
(`test: add the seven benchmark suites`).

## 9. Phase 6 — Documentation and docs tests ✅

- `docs/ARCHITECTURE.md`: runtime assembly, boundaries, what is real and what
  is modeled.
- `docs/DECISIONS.md`: dated decisions with consequences and rejected
  alternatives.
- `docs/BENCHMARKS.md`: harness contract, environment and the real tables.
- `docs/COMPLEXITY.md`: asymptotic claims, counters and their limits.
- README: status, chapter map, scenario, measured results and limitations.
- `tests/Documentation/DocumentationTest.php`: required files, links, chapter
  coverage, benchmark environment, no branch-pinned links.

**Definition of done:** `make test`, `make analyse` and `make format-check` are
green; every local link in the README resolves; no document links to `master`.

**Evidence:** `make test` → 74 tests, 3,468 assertions; PHPStan level 8 clean;
PHP-CS-Fixer clean. The docs test checks all six documents, the seven chapter
names, the local README links, the benchmark environment block, the seven
benchmark suites, the dated decision record, phases 0–9 and the absence of
`master` links.

**Commit:** `14854eb` (`docs: document the architecture, decisions and measurements`).

## 10. Phase 7 — Release `v1.0.0` ✅

- Create the public repository `Researcher86/architecture-decision-lab`.
- Push the commits and wait for green CI.
- Create the annotated tag `v1.0.0` on the release commit.
- Create the GitHub release with the chapter list, scenario, checks and
  environment.
- Do not move the tag afterwards.

**Definition of done:** the repository is public, CI is green, the annotated tag
exists, and the release notes link to the book.

**Evidence:**

- Public repository:
  [`Researcher86/architecture-decision-lab`](https://github.com/Researcher86/architecture-decision-lab).
- CI run
  [`40471843345`](https://github.com/Researcher86/architecture-decision-lab/actions/runs/40471843345)
  is green at `9d5f521a9ff850d278b6d47b30d3d3f923604a85`.
- Annotated tag `v1.0.0` (tag object `35afbaa452f0e2fce22967a1dd8891361b23fb02`)
  points to the release commit `9d5f521a9ff850d278b6d47b30d3d3f923604a85`.
- Release
  [`v1.0.0`](https://github.com/Researcher86/architecture-decision-lab/releases/tag/v1.0.0)
  lists the seven chapters, the scenario, checks, environment and limitations.
- The tag is immutable for this manuscript; later work does not move it.

## 11. Phase 8 — The Russian book ✅

- Seven chapters in `book/architecture-decision-lab/`, one per decision.
- Each chapter: problem, mechanism with exact excerpts, experiment, failure
  modes, tests and invariants, numbers, summary, exercises and what comes next.
- Code blocks are exact excerpts from the tagged source; every block is
  followed by a pinned GitHub link.
- Book README with status, reading order, run instructions, checker command and
  what is not promised.
- Formatting with Prettier; checker with `--expected-chapters=7`.

**Definition of done:** checker prints `ok` with zero failures; Book CI verifies
the new book and still verifies the existing books.

**Evidence:**

- Book commit
  `60e1c8f19a22bf88e983df55374d811759b93da6` (`docs: write the seven
  Architecture Decision Lab chapters`) adds eight Markdown files: seven chapters
  and the book README.
- Prettier 3.6.2 formatted the files with `--prose-wrap always --print-width
  80`; the checker was rerun afterwards.
- Checker output from `book/architecture-decision-lab`:

  ```text
  chapters=7 code_blocks=74 linked=51 exact=51 links=51 resolved=51 unique_links=50 local_links=7 local_resolved=7
  ok
  ```

- All 51 code blocks with a linked range are exact excerpts; all 51 GitHub
  links resolve, including the seven local links in the book README.

## 12. Phase 9 — Final synchronization ✅

- Add the Architecture Decision Lab step to
  `book/.github/workflows/book.yml`.
- Update the root `book/README.md` entry.
- Record the final book commit and CI run ID in this journal.
- Run `make check`; confirm the tag still points to the release commit; confirm
  both repositories are clean.

**Definition of done:** all checks in the process prompt are satisfied, and the
final report names the commits, tag, CI run ID, checker output and `make check`
result.

**Evidence:**

- Book CI commit
  `caa8e363a389afa06935baa10d9ea085981c8bb4` (`ci: verify Architecture Decision
  Lab book chapters`) adds one step to `book/.github/workflows/book.yml`; the
  workflow still verifies PHP Systems Lab, Algorithms Lab and Architecture Lab.
- The root `book/README.md` now lists Architecture Decision Lab as seven ready
  chapters pinned to `v1.0.0`.
- Book CI run
  [`40473648882`](https://github.com/Researcher86/engineering-lab-book/actions/runs/40473648882)
  is green at the full book commit `caa8e36`; the new chapter step passes
  alongside the existing books.
- Final `make check` in the project container: 74 tests, 3,468 assertions,
  PHPStan level 8 clean over 124 files, PHP-CS-Fixer clean.
- Final state: the lab is at `9d5f521`, the book is at `caa8e36`, both working
  trees are clean, and `v1.0.0` still points to `9d5f521`.

---

## Commit map

| Commit | Repository | Phase | Subject |
|---|---|---|---|
| `efc3cca` | lab | 1 | `chore: scaffold the Docker-first PHP project` |
| `cc20e76` | lab | 2 | `feat: model trip booking, transport, saga, outbox and projections` |
| `b37f303` | lab | 3 | `feat: run the trip-booking scenario and traveler reports` |
| `448e003` | lab | 4 | `feat: add the seven chapter experiments` |
| `87bc2f0` | lab | 5 | `test: add the seven benchmark suites` |
| `14854eb` | lab | 6 | `docs: document the architecture, decisions and measurements` |
| `9d5f521` | lab | 7 | `docs: record the documentation phase`; tagged `v1.0.0` |
| `60e1c8f` | book | 8 | `docs: write the seven Architecture Decision Lab chapters` |
| `caa8e36` | book | 9 | `ci: verify Architecture Decision Lab book chapters` |
