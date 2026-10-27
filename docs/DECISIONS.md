# Decisions — Architecture Decision Lab

A record of the technical and process decisions that shaped the lab, with the
reason each was made and the cost it accepted. New decisions are appended, not
rewritten; a later decision supersedes an earlier one explicitly.

## 2026-10-27 — One trip-booking domain for seven chapters

- The domain is a package booking: one flight, one hotel, one car and one
  payment. A deterministic failure at payment forces either a local rollback or
  a compensation.
- Using one domain keeps the chapters comparable. A chapter changes the
  deployment shape, the storage, the delivery mechanism or the transaction
  model; it does not change the business question.
- The alternative — seven small unrelated examples — would make each chapter
  easier to isolate but impossible to read as one system. The book is about
  decisions that interact, so the lab has to keep the interactions visible.
- Consequence: some experiments bundle two decisions (for example,
  microservices with a database per service and a saga). The reports say so
  instead of claiming a single-variable experiment.

## 2026-10-27 — Docker is the only supported development environment

- Every command runs through `make` and `docker compose exec php` in the
  `php:8.5-cli` image. Host PHP, Composer and extensions are not supported.
- CI runs the same make targets in the same image, so there is no second
  environment to drift from.
- The project directory is mounted at `/app`; generated databases and results
  live under `var/` and stay out of git.
- Consequence: absolute benchmark timings include Docker Desktop's Linux VM.
  They are useful for relative comparisons and operation counts, not as
  universal constants.

## 2026-10-27 — PHP 8.5 and no runtime framework

- `composer.json` requires `php: ^8.5` and the PDO SQLite extensions. Composer
  is for development tools only; the runtime has no framework and no third-party
  dependency.
- The lab is meant to be read. A framework would hide the transaction boundary,
  the event bus and the transport behind configuration that the book would then
  have to explain separately.
- Consequence: HTTP, routing, authentication and a production process model are
  absent. The lab is a library plus command-line harness, not a web service.

## 2026-10-27 — SQLite is the concrete store; the document adapter is not MongoDB

- `SqliteDatabase` is a real PDO SQLite store with real transactions, indexes
  and SQL. It is the source of truth in every configuration.
- `DocumentBookingStore` stores the whole booking aggregate as one JSON
  document in a `documents` table. It is a document-shaped adapter on SQLite,
  not a MongoDB driver and not a model of MongoDB's indexes, replication or
  read concerns.
- The comparison is therefore about query shape and aggregate boundaries, not
  about "SQL versus Mongo". The book must not generalize the measured document
  numbers to a real document database.
- Consequence: the document store has no secondary indexes beyond the primary
  key. A traveler query scans every document, which is exactly the cost the
  chapter needs to expose.

## 2026-10-27 — Remote calls are deterministic in-process models

- `RemoteTransport` wraps a closure, advances a `VirtualClock` by a configured
  latency, and counts request and response bytes. It never sleeps.
- Determinism is more valuable than realism here: the scenario must produce the
  same timeline and the same counters on every run, and the test suite must not
  depend on wall-clock sleeps.
- The transport can fail before or after the wrapped work. The "after" failure
  is the lost-response case that separates a local rollback from a saga
  compensation.
- Consequence: network jitter, connection pools, timeouts, retries and partial
  responses are not modeled. The logical latency is a cost marker, not a
  prediction of production latency.

## 2026-10-27 — `LabConfig` rejects impossible combinations

- A local transaction with `DatabaseMode::PerService` is rejected at
  construction. One SQLite transaction cannot span independent files, and a lab
  that silently allowed it would make the database-per-service chapter lie.
- The same guard rejects negative projection lag, negative remote latency and a
  queue limit below one.
- Consequence: experiments must choose a transaction model that matches the
  database ownership. There is no "best effort" fallback that hides the
  mismatch.

## 2026-10-27 — Local transaction, saga and two-phase commit are separate paths

- `TransactionMode` selects an explicit implementation:
  - `local` uses one SQLite transaction for reservations, booking state and the
    outbox row;
  - `saga` commits each step and compensates the failed step plus completed
    steps in reverse;
  - `two-phase-commit` prepares holds first, then commits, and exposes
    recovery when the coordinator crashes after prepare;
  - `none` is a baseline that writes without atomicity or compensation.
- The saga compensates the failed step because a lost response may mean the
  effect exists. It does not assume that an exception implies "nothing
  happened".
- Consequence: the saga has a visible intermediate state and can over-compensate
  in a real system if compensations are not idempotent. The lab makes every
  compensation idempotent and records the attempts.

## 2026-10-27 — A successful saga leaves payment authorized, not captured

- The booking boundary ends when the flight, hotel and car are reserved and the
  payment is authorized. Capture and settlement are a separate business
  process.
- Capturing inside the saga would create a second irreversible effect that
  cannot be compensated by a void. It would also make the saga look more atomic
  than it is.
- Consequence: the confirmed booking owns an authorization, and a later
  settlement job is out of scope. The book must state this limitation when it
  discusses payment consistency.

## 2026-10-27 — Two-phase commit uses logical holds and explicit recovery

- Prepare decrements capacity and stores a prepared reservation or payment
  authorization. Those rows are holds: another booking cannot use the capacity.
- If the coordinator crashes after every prepare but before the commit
  decision, the result is `pending` and the holds remain. Recovery takes an
  explicit `commit` or `abort` decision.
- The coordinator does not persist the decision before replying, because this
  is a single-process model. The lab reports the blocking window instead of
  pretending that a real recovery protocol exists.
- Consequence: a 2PC crash leaves four holds in the experiment. That is the
  measured cost, not a bug to hide.

## 2026-10-27 — The outbox is at-least-once; the projector is idempotent

- The booking state change and the outbox row are written in one transaction.
  The dispatcher may deliver an event twice, but it cannot lose it between the
  commit and the publish.
- `IdempotentProjector` applies the event and records `(consumer, event_id)` in
  the same reporting-database transaction. A duplicate is skipped.
- `DeliveryMode::DualWrite` exists as the negative control: it saves the
  booking and then publishes as a second operation, so a crash between them
  leaves a confirmed booking without an event.
- Consequence: the consumer must be idempotent. The lab does not provide
  exactly-once delivery, because no broker in this model does.

## 2026-10-27 — The async queue is bounded and rejects before creating a booking

- A full `JobQueue` returns a rejected result with an empty booking id. It does
  not create a pending booking row that no worker will ever process.
- Business failures are cancelled, not retried. Only infrastructure failures
  would be retryable, and the lab does not implement a retry/backoff policy.
- Consequence: the bounded experiment accepts 10 of 50 bookings with a queue
  limit of 10. That is an explicit admission-control decision, not a silent
  drop.

## 2026-10-27 — Logical time and wall-clock time are different instruments

- `VirtualClock` advances only through modeled latency, queue lag and explicit
  `advance()` calls. It makes the scenario and tests deterministic.
- The benchmark harness uses `hrtime()` and reports median, p95, max, ops/sec
  and PHP allocator deltas. It never mixes logical milliseconds into a
  wall-clock table.
- Consequence: the experiment's "average 8.13 ms" for microservices is logical
  latency (16 remote calls × ~2 ms across 30 bookings), not a measured network
  time. The book must label it as such.

## 2026-10-27 — Experiments compare reference configurations, not isolated knobs

- A chapter's reference configurations are the ones a team would actually
  choose: monolith with a shared database and a local transaction, modular
  monolith with a shared database and a local transaction, microservices with a
  database per service and a saga.
- Changing only the deployment shape while keeping the shared database would
  isolate a variable but describe a configuration nobody deploys. The report
  documents the bundled changes and the confounders.
- Consequence: the topology benchmark shows the combined cost of the
  microservice boundary, the database split and the saga. The chapter explains
  which part is which, and the transaction benchmark provides a narrower view of
  the transaction cost.

## 2026-10-27 — No generated data or `var/` contents in git

- Databases, experiment text/JSON and benchmark reports are generated under
  `var/` and ignored, except for `.gitkeep` files.
- The source of truth is the code and the commands, not a checked-in snapshot
  that can drift from the code that produced it.
- Consequence: a reader reproduces the numbers with `make experiment` and
  `make benchmark`; the book quotes the outputs it actually observed and pins
  the code to a release tag.

## 2026-10-27 — The book is written after the code and pinned to `v1.0.0`

- Chapters are written only after the implementation, tests, experiments and
  benchmarks are stable. A chapter cannot defend a feature that does not exist.
- Every code excerpt is an exact copy of the tagged source, and every GitHub
  link targets `v1.0.0` or a full SHA, never `master`.
- The checker verifies chapter count, closed fences, exact excerpts, line ranges
  and resolvable links. A broken link is a documentation bug, not a warning.
- Consequence: late code changes require a new tag and a new book revision. The
  `v1.0.0` tag is immutable.
