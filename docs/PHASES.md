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
│   ├── tests/                       # unit, differential, invariant and docs tests
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

| # | Chapter | Core question | Planned experiment |
|---|---|---|---|
| 01 | Monolith vs Modular Monolith vs Microservices | what does a deployment boundary buy, and what does it cost? | `topology:deployment` |
| 02 | Sync vs Async Processing | when is a 202 honest and when is it hiding failure? | `processing:sync-async` |
| 03 | SQL vs NoSQL | which query shape and transaction boundary does the data need? | `storage:sql-document` |
| 04 | Database per Service | what happens when a join becomes a service call? | `database:shared-per-service` |
| 05 | Outbox Pattern | how does an event survive the gap between commit and publish? | `outbox:delivery` |
| 06 | Saga vs Distributed Transaction | atomicity, locks and compensation under failure | `transaction:saga-2pc` |
| 07 | Eventual Consistency | stale reads, convergence and read-your-writes | `consistency:projection-lag` |

The MVP scenario is `scenario:trip-booking`: a traveller books a package of
flight, hotel and car, payment is authorized, and a deterministic failure
forces either a rollback or a compensation. It is one command and one report.

---

## 3. Phase 0 — Context and plan ✅

- Read the draft, the process prompt and the `algorithms-lab` reference.
- Fix the trip-booking domain, the seven chapters and the MVP scenario.
- Record the stack and the non-goals.

**Definition of done:** the scope, chapter order, scenario and limitations are
written down; no code depends on an unstated requirement.

## 4. Phase 1 — Scaffold and harness ✅

- `composer.json`, `Dockerfile`, `docker-compose.yml`, `Makefile`, PHPUnit,
  PHPStan, PHP-CS-Fixer, `.gitignore`, `.editorconfig`, CI workflow.
- Discoverable experiment and benchmark descriptors.
- Environment block: PHP, OS, architecture, CPU, memory limit, cgroup,
  OPcache, JIT, Xdebug, allocator, commit.
- Demo experiment and demo benchmark.

**Definition of done:** `make build`, `make install`, `make test`,
`make analyse`, `make format-check` are green before chapter code exists.
