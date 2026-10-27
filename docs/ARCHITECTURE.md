# Architecture — Architecture Decision Lab

This document describes what is assembled at runtime, which boundaries are real,
and which parts are a model. It is deliberately narrower than the book: the book
explains why a decision is expensive; this file explains where the code puts the
cost.

## One domain, seven decisions

Every experiment books the same package: one flight, one hotel and one car,
paid once. The domain does not change between chapters. What changes is the
`LabConfig`:

| Decision | Enum | Values used in the lab |
|---|---|---|
| Deployment shape | `DeploymentShape` | `monolith`, `modular-monolith`, `microservices` |
| Processing | `ProcessingMode` | `sync`, `async` |
| Storage shape | `StorageMode` | `sql`, `document` |
| Database ownership | `DatabaseMode` | `shared`, `per-service` |
| Event delivery | `DeliveryMode` | `outbox`, `dual-write` |
| Transaction model | `TransactionMode` | `local`, `saga`, `two-phase-commit`, `none` |
| Read consistency | `ConsistencyMode` | `strong`, `eventual` |

`LabConfig` rejects a combination that cannot be honest. The important example
is a local transaction with a database per service: one SQLite transaction
cannot span several files, so the factory refuses the configuration instead of
pretending that atomicity exists.

```php
if ($this->transaction === TransactionMode::Local && $this->database === DatabaseMode::PerService) {
    throw new \InvalidArgumentException(
        'A local transaction cannot be atomic across databases; use saga or two-phase-commit',
    );
}
```

## Runtime assembly

`LabFactory` is the composition root. It creates the databases, the transport,
the stores, the inventory and payment services, the event bus, the outbox, the
projection, the workflow, the optional queue and the traveler report. Every
experiment and the MVP scenario go through this one factory, so the same
counters and the same failure injection apply everywhere.

```text
LabConfig
   │
   ├── SqliteDatabase(s)        shared.sqlite or booking/flight/hotel/car/payment/reporting
   ├── Transport                direct | module | remote(logical latency, bytes)
   ├── BookingStore             SqlBookingStore | DocumentBookingStore
   ├── InventoryService × 3     conditional UPDATE + reservations table
   ├── PaymentService           authorized | captured | voided
   ├── Outbox + Dispatcher      pending → published, at-least-once
   ├── EventBus + Projection    immediate or queued, idempotent consumer
   ├── BookingWorkflow          local | saga | 2PC | none
   ├── AsyncBookingProcessor    bounded queue + worker drain
   ├── TripReport               SQL join | service calls
   └── Lab                      narrow public surface for experiments
```

### Shared database

`shared.sqlite` contains every table: bookings, booking lines, outbox, the three
inventory tables, reservations, payments and the reporting views. This is the
only configuration in which `SqlBookingStore::transaction()` can wrap inventory
writes in the same SQLite transaction. It is also the only configuration where
the traveler report can be one SQL join.

### Database per service

Six files are created: `booking.sqlite`, `flight.sqlite`, `hotel.sqlite`,
`car.sqlite`, `payment.sqlite` and `reporting.sqlite`. No transaction spans two
of them. The booking workflow therefore has to use a saga or two-phase commit,
and the report has to call the inventory services for every booking.

The reporting database is also separate. The projection is a consumer with its
own processed-event table; it is not a view over the booking database.

### Transport boundaries

The transport is a model of a call boundary, not a network stack:

- `DirectTransport` is used by the monolith. It counts `directCalls` and injects
  faults, but it does not add latency or bytes.
- `ModuleTransport` is used by the modular monolith. It counts `moduleCalls`;
  the call crosses a module interface but not a process.
- `RemoteTransport` is used by microservices. It adds `remoteCalls`, network
  request and response bytes, and a deterministic logical latency. It never
  sleeps: wall-clock time stays in the benchmark harness.

The failure that matters most is the one injected *after* the work:

```php
public function after(string $step): void
{
    $this->maybeFail($step, true);
}
```

`after` models "the remote side committed, the caller lost the response". A
local transaction can roll the effect back. A saga cannot: it must compensate a
step whose effect may exist. That distinction is the reason the transaction
chapter exists.

## Booking workflow

`BookingWorkflow::submit()` creates a pending booking row. `execute()` then
chooses one of four paths:

### Local transaction

```php
$lines = $this->store->transaction(function () use ($booking, &$eventPublished): array {
    $lines = $this->reserveAll($booking->id);
    $eventPublished = $this->finalize($booking, BookingStatus::Confirmed, $lines);

    return $lines;
});
```

All writes go through one SQLite connection. A failure rolls back the
reservations, the booking status and the outbox row together. This path is only
legal when the database is shared.

### Saga

The steps are `flight.reserve`, `hotel.reserve`, `car.reserve` and
`payment.authorize`. Each step commits on its own. On failure the coordinator
compensates the failed step first, then the completed steps in reverse order.
The failed step is included because a lost response means its effect may exist
even though the caller saw an exception.

Compensations are idempotent: releasing a missing, released or prepared
reservation is safe. A payment void of an unknown payment is a recorded no-op.

The saga deliberately leaves a successful payment in `authorized`, not
`captured`. Capture and settlement happen outside the booking boundary. The
lab does not pretend that a saga makes the payment and the reservations atomic;
it only makes the booking outcome consistent with a compensation path.

### Two-phase commit

`TwoPhaseCommitCoordinator` prepares all four participants (three inventory
holds and a payment authorization), then commits them. Prepare is a real hold:
capacity is decremented and other bookings cannot use it. If a prepare fails,
the coordinator aborts every attempted participant.

If the process crashes after prepare and before the commit decision, the
prepared rows remain. The result is `pending`, and recovery is an explicit
decision:

```php
$result = $coordinator->recover($participants, $decision);
```

`recover('commit')` commits the holds and captures the payment;
`recover('abort')` releases or voids them. A real coordinator would persist the
decision before replying to participants. This lab keeps the decision in the
report so the blocking window is visible.

### No transaction

`TransactionMode::None` is a teaching baseline, not a recommendation. It writes
state without a transaction or compensation and is used only to show what the
other modes prevent.

## Event delivery and projection

The outbox row is written in the same database transaction as the booking
outcome. `OutboxDispatcher` delivers pending rows and marks them published.
Delivery is at-least-once:

- a crash before the publish leaves the row pending and the next dispatch
  retries it;
- a crash after the publish but before `markPublished()` repeats the event;
- `IdempotentProjector` records `(consumer, event_id)` in the same transaction
  as the projection update, so a duplicate is absorbed rather than applied
  twice.

`DeliveryMode::DualWrite` is the negative control. It saves the booking and then
publishes to the in-memory bus as a second operation. A failure between the two
leaves a confirmed booking with no event. `BookingResult::$eventPublished`
records the difference instead of hiding it.

With `ConsistencyMode::Strong`, the projection listener applies the event
immediately. With `ConsistencyMode::Eventual`, it enqueues the event with a
configured lag; `ProjectionRunner::drain()` advances logical time to each due
event. The source of truth and the projection are separate databases, so the
queue can be inspected while it is still stale.

## Async processing

`AsyncBookingProcessor` checks the bounded queue *before* it creates a booking
row. A full queue returns a rejected result with an empty booking id, so the
store does not accumulate phantom pending bookings. A worker drains jobs and
executes the same `BookingWorkflow`.

Business failures are not retried: a payment decline is a cancelled booking,
not an infrastructure error. The lab does not implement a delayed retry policy,
a dead-letter queue or worker leases. Those are stated as limitations, not
simulated as if they existed.

## Storage adapters

`SqlBookingStore` uses normalized tables: a booking row plus one row per
booking line. `DocumentBookingStore` stores the whole aggregate as one JSON
document in a `documents` table. The document adapter is a document-shaped store
on SQLite; it is not MongoDB and does not model a document database's indexes,
replication or consistency settings.

Both implement the same `BookingStore` interface, so the workflow and tests can
run against either. The costs are different:

- a point read is two SQL statements and four rows in SQL, one row in the
  document store;
- a traveler query is one join in SQL, a full collection scan in the document
  store;
- a status count is an aggregate over one row in SQL, a scan and JSON decode of
  every document in the document store.

## Traveler report

There are two implementations of `TripReport`:

- `SqlTripReport` joins bookings to the three inventory tables. It is only
  selected when the database is shared and the storage is SQL.
- `ServiceTripReport` reads the booking list and then asks each inventory
  service for the current availability of every resource. It is the honest
  implementation when the data is owned by separate services.

The service report is the N+1 shape: one query for the bookings plus three
calls per booking. The measured counts are in `docs/COMPLEXITY.md`.

## MVP scenario

`TripBookingScenario` fixes the seven decisions to the reference
microservice-style configuration: per-service databases, a saga, an outbox and
an eventual projection. It seeds capacity 4, confirms the first booking, fails
payment on the second, compensates four steps, dispatches two events and drains
the projection. It then checks invariants:

- the first booking is confirmed and its three reservations are committed;
- the second booking is cancelled, its reservations are released and its
  payment is gone;
- available capacity in the source and in the projection converges to 3;
- the outbox is empty and the projection has one trip per booking;
- the second booking records at least four compensation attempts.

The scenario is deterministic in logical time. It is one command (`make
scenario`) and one report.

## What is real and what is modeled

Real:

- SQLite files, transactions, constraints and SQL execution;
- the booking, inventory, payment, outbox, projection and reporting code;
- failure injection at named steps and the resulting state;
- operation counters, row counts, byte counts and wall-clock benchmarks.

Modeled:

- remote calls (in-process closures with logical latency and byte counters);
- two-phase commit (a logical coordinator, not a distributed transaction
  manager);
- the document store (document-shaped SQLite, not MongoDB);
- the event bus (in-process, not Kafka or RabbitMQ);
- process crashes (exceptions at named points, not killed processes);
- concurrency (one process, one SQLite connection per database).

Not implemented:

- authentication, authorization, real payment capture or settlement;
- retries with backoff, dead-letter queues or lease-based workers;
- replication, sharding, multi-node consensus or failure detection;
- schema migrations;
- a real network or a real broker;
- production observability (tracing, metrics export, structured logs).

These are boundaries of the experiment, not features hidden behind a flag.

## Directory map

```text
src/
  Consistency/     bus, outbox, projection, idempotent consumer
  Domain/          enums, booking aggregate, trip request, result objects
  Experiment/      descriptor, registry, options, output
  Foundation/      clock, ids, fault injection, deterministic random
  Instrumentation/ counters, memory meter, stopwatch
  Integration/     LabConfig, LabFactory, Lab, MVP scenario
  Processing/      bounded job queue and async processor
  Reporting/       SQL join and service-call traveler reports
  Saga/            saga coordinator and two-phase coordinator
  Services/        booking workflow, inventory, payment, transports
  Storage/         SQLite wrapper, schema, SQL and document stores
experiments/       one directory per chapter, discovered by the runner
benchmarks/        one suite per chapter
tests/             unit, integration, scenario, harness and documentation tests
docs/              architecture, decisions, benchmarks, complexity, phases
```
