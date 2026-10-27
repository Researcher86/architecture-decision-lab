# Architecture Decision Lab

**[🧪 Engineering Lab](https://github.com/Researcher86/engineering-lab-book)** · Architecture decisions · 7 chapters

> An executable laboratory for the decisions that are cheap to draw and
> expensive to live with: where a monolith ends, what asynchronous processing
> really buys, when a document store wins, and what a distributed transaction
> costs when it is not available.

This repository is the code behind the seven chapters of Architecture Decision
Lab. It contains one deterministic trip-booking system, seven executable
treatments of the same flow, measured experiments, benchmark suites and an
end-to-end scenario.

**Status: `v1.0.0`.** Seven chapters, PHPStan level 8, a green CI and a Russian
book generated from the tagged source.

## 30-second demo

Requires Docker. Nothing is installed on the host.

```bash
make build       # build php:8.5-cli with the project extensions
make install     # composer install inside the container
make check       # PHPUnit + PHPStan level 8 + PHP-CS-Fixer
make scenario    # the one end-to-end trip-booking flow
```

The scenario output is deterministic:

```text
t=9  booking trip-0001: confirmed after 8 ms, compensation attempts 0
t=26 booking trip-0002: cancelled after 16 ms, compensation attempts 4
t=26 outbox has 2 pending event(s); the projection still reports trip-0001 as absent
t=76 projection drained 2 event(s); booking trip-0001 is now confirmed
scenario passed
```

The first booking reserves a flight, a hotel and a car, and authorizes payment.
Payment for the second booking fails before the effect; the saga compensates the
three reservations and the payment attempt. The outbox carries both outcomes to
a projection that is deliberately lagging, so the report shows stale reads
before convergence.

Summary: 2 bookings, 1 confirmed, 1 cancelled, 4 compensation attempts, 2
events delivered, 2 stale reads, then source and projection both converge to 3
available units.

## The seven chapters

| # | Decision | Experiment | Benchmark |
|---|---|---|---|
| 01 | Monolith vs modular monolith vs microservices | `topology:deployment` | `01-topology` |
| 02 | Sync vs async processing | `processing:sync-async` | `02-processing` |
| 03 | SQL vs document storage | `storage:sql-document` | `03-storage` |
| 04 | Database per service | `database:shared-per-service` | `04-database` |
| 05 | Outbox pattern | `outbox:delivery` | `05-outbox` |
| 06 | Saga vs two-phase commit | `transaction:saga-2pc` | `06-transaction` |
| 07 | Eventual consistency | `consistency:projection-lag` | `07-consistency` |

Every chapter has an implementation, tests, counters, a deterministic
experiment, a wall-clock benchmark and English documentation. The Russian book
is written after the code and pinned to the release tag.

## Measured results

One run in Docker on macOS, commit `87bc2f0`, five repetitions. These are
development numbers, not universal constants. The full environment, per-suite
tables and caveats are in [docs/BENCHMARKS.md](docs/BENCHMARKS.md).

| Measurement | Result |
|---|---|
| Monolith booking vs microservices booking | 1,291 µs vs 1,609 µs median (+24.6%) |
| Async submit vs sync booking | 191 µs vs 1,189 µs median (6.2× cheaper submit) |
| Async roundtrip vs sync booking | 1,333 µs vs 1,189 µs (+12.1%) |
| SQL point read vs document point read | 17.8 µs vs 9.3 µs (document 1.9× faster) |
| SQL traveler query vs document traveler query | 84.3 µs vs 452.1 µs (document 5.4× slower) |
| Shared report vs database-per-service report | 40.0 µs vs 563.0 µs (14.1× slower) |
| Local transaction vs saga vs 2PC | 1,236 µs / 1,592 µs / 2,036 µs |
| Dual-write crash | 1 confirmed booking, 0 events, 1 lost event |
| Outbox crash | 1 confirmed booking, 1 event retried, 0 lost |
| Eventual projection | 100/100 stale reads before drain, 0/100 after |

The durable result is not the milliseconds. It is the shape: a document store
makes the aggregate point read cheaper and the cross-aggregate query more
expensive; a database per service turns one join into `1 + 3N` calls; an outbox
trades a duplicate for a lost event; a two-phase commit trades a blocking hold
for atomicity.

## Commands

```bash
make experiment                              # list experiments
make experiment ARGS="topology:deployment"
make experiment ARGS="storage:sql-document --bookings=100 --travelers=10"
make experiment ARGS="transaction:saga-2pc"
make scenario                                # the MVP flow

make benchmark                               # list benchmark suites
make benchmark ARGS="03-storage"
make benchmark ARGS="04-database --repetitions=9"
```

Open a shell in the same container with `make shell`; stop it with `make down`.
All targets create the container if it is not running.

## What is real and what is modeled

Real: SQLite files and transactions, the booking and inventory logic, the
outbox and projection code, failure injection at named steps, operation
counters, row and byte counts, and the wall-clock benchmarks.

Modeled: remote calls are in-process closures with logical latency and byte
counters; two-phase commit is a logical coordinator, not a distributed
transaction manager; the document store is document-shaped SQLite, not MongoDB;
the event bus is in-process, not Kafka; crashes are exceptions at named points,
not killed processes; there is one process and no concurrency.

Not implemented: authentication, real payment capture or settlement, retries
with backoff, dead-letter queues, leases, replication, sharding, consensus,
schema migrations, a real network, a real broker or production observability.
These are boundaries of the experiment, not hidden features.

## Documentation

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — runtime assembly, boundaries,
  data and the real/modeled split.
- [docs/DECISIONS.md](docs/DECISIONS.md) — dated decisions and trade-offs.
- [docs/BENCHMARKS.md](docs/BENCHMARKS.md) — how numbers are taken and read.
- [docs/COMPLEXITY.md](docs/COMPLEXITY.md) — asymptotic costs and measured
  counters.
- [docs/PHASES.md](docs/PHASES.md) — the build journal and the release plan.

The Russian book lives in the
[engineering-lab-book](https://github.com/Researcher86/engineering-lab-book)
repository; the release is
[v1.0.0](https://github.com/Researcher86/architecture-decision-lab/releases/tag/v1.0.0).

## Stack

- PHP 8.5 CLI in Docker, strict types, no runtime framework.
- PDO SQLite as the concrete store; the document adapter is explicitly a
  document-shaped store on SQLite, not MongoDB.
- PHPUnit, PHPStan level 8 and PHP-CS-Fixer.
- Composer is for development tools only; there are no runtime dependencies.

## License

MIT.
