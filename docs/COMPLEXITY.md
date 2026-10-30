# Complexity and Cost — Architecture Decision Lab

This document separates three things that are easy to conflate:

1. **Asymptotic work** — how the number of operations grows with bookings,
   events, services or documents.
2. **Measured counters** — what the lab actually did in a deterministic run.
3. **Wall-clock time** — what the benchmark harness observed in this container.

A wall-clock number is evidence about this machine. A counter is evidence about
the code. A complexity claim is a statement about growth. None of them replaces
the other two.

## The booking path

The package is fixed at three resources and one payment, so the per-booking
constants are stable across chapters. What changes is the coordination:

| Mode | Durable steps | Coordination | Failure cost |
|---|---|---|---|
| `local` | 3 reservations + 1 authorization + booking + outbox | one SQLite transaction | rollback; no compensation |
| `saga` | 4 committed steps + booking + outbox | orchestrated, no global transaction | failed step + completed steps compensated in reverse |
| `two-phase-commit` | 4 prepares + 4 commits + booking + outbox | prepare/commit with holds | holds remain until recovery decides |
| `none` | 3 reservations + 1 authorization + booking | none | partial state is possible |

With `S` saga steps and `C` completed steps at failure, the worst case is
`S + C` compensation attempts (the failed step plus the completed ones). For the
four-step trip booking, that is at most 8 attempts; the measured payment
failure is 4 because the payment failed before it completed and the three
reservations did complete.

With `P` two-phase participants, prepare is `P` holds and commit is `P` writes.
For the trip booking `P = 4` (flight, hotel, car, payment). A crash after
prepare leaves all 4 holds; recovery is another `P` commits or aborts. The
measured `twoPhaseHoldsGauge` is 4, and flight availability is 9 instead of 10
until recovery.

The async path adds a queue:

- `submit` is O(1) plus one booking insert and one enqueue;
- memory is bounded by `queueLimit`;
- a full queue rejects before creating a booking row;
- `drain` processes at most `workers × batchSize` jobs per call.

The educational `JobQueue` uses `array_shift()`, so a pop is O(n) and draining
`n` jobs is O(n²). The async roundtrip benchmark drains one job at a time, which
is why its numbers do not expose that cost. A production queue would use a ring
buffer or a linked structure; this lab does not.

## Counters from the deterministic experiments

All numbers below are from `var/results/*.txt` at the benchmark commit. Counters
are not reset between schema creation, seeding and the measured work unless the
experiment says so. The storage and report experiments reset before the
operation they compare; the topology and scenario counters are end-to-end.

### Topology (30 bookings, one lost response)

| Shape | Confirmed | Cancelled | Compensations | Remote calls | SQL queries | Logical avg latency |
|---|---:|---:|---:|---:|---:|---:|
| monolith | 29 | 1 | 0 | 0 | 1,012 | 0.00 ms |
| modular monolith | 29 | 1 | 0 | 0 | 1,012 | 0.00 ms |
| microservices | 29 | 1 | 3 | 122 | 963 | 8.13 ms |

The monolith and modular monolith roll back the lost car write; the
microservices configuration compensates it. The 122 remote calls are the cost of
crossing the service boundary; the 8.13 ms is logical time (roughly four calls at
2 ms per booking, with 122 calls across 30 bookings including the failed booking
and its compensations), not measured network time. The SQL counts include schema
creation, seeding and all 30 bookings; they are not a per-booking query count.
The microservice count is lower because of the booking path, not the schema: the
local path re-reads the booking before reserving (`reserveAll()` calls
`store->get()`, two queries) and the saga does not, so a confirmed booking costs
33 queries locally and 31 under the saga. The failed booking's compensations
take back 9 of the 58 saved: 1,012 - 963 = 49.

### Sync vs async (50 bookings, two workers, queue limit 50)

| Case | Accepted | Rejected | Confirmed | Avg client latency | Avg completion latency | Max queue | SQL queries |
|---|---:|---:|---:|---:|---:|---:|---:|
| sync | 50 | 0 | 50 | 0.00 ms | 0.00 ms | 0 | 1,680 |
| async | 50 | 0 | 50 | 1.00 ms | 25.50 ms | 50 | 1,780 |
| async, queue limit 10 | 10 | 40 | 10 | 1.00 ms | 5.50 ms | 10 | 380 |

The synchronous path has no logical queue delay; its wall-clock cost is in the
benchmark, not in this table. The async client returns after a submit (1 ms of
logical time in this configuration), while the booking completes at 25.50 ms.
The bounded run rejects 40 of 50 submissions instead of letting the queue grow.
Those 40 rejections create no booking rows.

### Storage (100 bookings, 10 travelers; counters reset)

| Operation | Store | SQL queries | Rows read | Bytes read | Documents scanned | Documents read |
|---|---|---:|---:|---:|---:|---:|
| point read | SQL | 2 | 4 | 478 | 0 | 0 |
| point read | document | 1 | 1 | 524 | 0 | 1 |
| traveler query | SQL | 10 | 300 | 83,356 | 0 | 0 |
| traveler query | document | 10 | 1,000 | 524,830 | 1,000 | 100 |
| status count | SQL | 1 | 1 | 36 | 0 | 0 |
| status count | document | 1 | 100 | 52,483 | 100 | 0 |

For `B` bookings, the SQL traveler query reads the joined rows for those
bookings (`3B` line rows) and returns one result per booking. The document query
scans every document in the collection and decodes the matching ones. The
status count is O(1) rows in SQL and O(total documents) in the document store.

The document store does not have a secondary index on `traveler_id`. A real
document database could add one, and then this table would look different. The
point is the shape: "store the aggregate together" makes the point read cheap
and the cross-aggregate query expensive.

### Traveler report (20 bookings)

| Case | Report rows | SQL queries | Remote calls | Request bytes | Avg booking logical latency |
|---|---:|---:|---:|---:|---:|
| shared database, local report | 20 | 1 | 0 | 0 | 8.00 ms |
| database per service, service report | 20 | 61 | 60 | 3,840 | 8.40 ms |

The service report is the N+1 shape: `1 + 3N` queries for `N` bookings, plus
three remote calls per booking. For 20 bookings, `1 + 60 = 61` queries and
`60 × 64 = 3,840` request bytes. The response bytes are another 3,840. The
shared report is one join. The 8.00 vs 8.40 ms column is the *booking* latency
of the run (four remote calls at 2 ms each), not the report cost; the report
adds 60 remote calls after the bookings. The experiment reports the query and
call counts for the report, not a separate report latency. The important
comparison is the shape, not the logical average.

### Outbox (20 bookings)

| Case | Confirmed | Events published | Projection trips | Lost events | Pending | Duplicates | Failures |
|---|---:|---:|---:|---:|---:|---:|---:|
| dual write | 20 | 20 | 20 | 0 | 0 | 0 | 0 |
| outbox | 20 | 20 | 20 | 0 | 0 | 0 | 0 |
| dual write, crash before publish | 1 | 0 | 0 | 1 | 0 | 0 | 1 |
| outbox, crash before publish | 1 | 1 | 1 | 0 | 0 | 0 | 1 |
| outbox, crash after publish | 1 | 1 | 1 | 0 | 0 | 1 | 1 |

The outbox row is one extra insert per event. The durable difference is the
lost-event column: the dual write cannot recover the event after the booking
commit, while the outbox retries it. The duplicate column is the price of
at-least-once delivery; the projector absorbs it with one processed-event row.

### Saga vs two-phase commit

| Case | Status | Compensations | Holds | Flight available | Payment |
|---|---|---:|---:|---:|---|
| saga, lost response | cancelled | 3 | 0 | 10 | none |
| saga, payment failure | cancelled | 4 | 0 | 10 | none |
| 2PC, prepare abort | cancelled | 3 | 0 | 10 | none |
| 2PC, crash then commit | confirmed | 0 | 4 | 9 | captured |
| 2PC, crash then abort | cancelled | 4 | 4 | 10 | voided |

`holds` is the gauge captured after the first attempt, before recovery. The
commit recovery leaves the holds in place and commits them; the abort recovery
releases them. The gauge is a snapshot, not a count of rows left at the end.

### Eventual consistency (20 bookings, 100 reads, 50 ms lag)

| Case | Stale reads before drain | Stale reads after drain | Queued | Observed lag | Applied | Source available | Projected available |
|---|---:|---:|---:|---:|---:|---:|---:|
| strong | 0 | 0 | 0 | 0 ms | 0 | 10 | 10 |
| eventual | 100 | 0 | 20 | 50 ms | 20 | 10 | 10 |

The eventual projection is stale for the whole pre-drain read loop: 100 of 100
reads see no confirmed trip. After the drain, the same 100 reads see the
confirmed trip. The queue holds 20 events, and the drain advances logical time
by the 50 ms lag. The source and the view converge to 10. This is the cost that
the benchmark cannot show: a window of stale reads, not a permanent divergence.

### Scenario counters (2 bookings)

The MVP scenario is end-to-end, so its counters include schema creation,
seeding, two bookings, one failure, four compensations, two outbox events and
two projection applies:

| Counter | Value | Meaning |
|---|---:|---|
| `bookingsCreated` | 2 | pending rows created |
| `bookingsConfirmed` | 1 | first booking |
| `bookingsCancelled` | 1 | second booking |
| `compensations` | 4 | payment void + three releases |
| `outboxAppends` | 2 | one event per booking outcome |
| `outboxPublished` | 2 | delivered by the dispatcher |
| `projectionApplied` | 2 | both events applied |
| `remoteCalls` | 16 | 12 booking/compensation calls + 4 availability reads |
| `networkRequestBytes` | 1,024 | 16 × 64 |
| `networkResponseBytes` | 960 | 15 × 64; the failed authorize has no response |
| `virtualLatencyMs` | 32 | 16 × 2 ms |
| `sqlQueries` | 135 | schema, seed, bookings, outbox and projection |
| `sqlRowsWritten` | 48 | all writes in the run |
| `sqlRowsRead` | 23 | all reads in the run |
| `failuresInjected` | 1 | payment authorization, before the effect |

The scenario's `projection_stale_reads` summary is 2: the confirmed booking is
absent from the view and the flight availability is still 4 when read before the
drain. After the drain, both converge to 3. The scenario asserts these values as
invariants.

## Growth summary

| Operation | Growth | Evidence |
|---|---|---|
| point read, SQL | O(1) statements, O(L) rows for `L` lines | 2 queries, 4 rows |
| point read, document | O(1) row, O(size) decode | 1 query, 1 row |
| traveler query, SQL | O(B) rows with an index, one statement per traveler | 10 queries, 300 rows |
| traveler query, document | O(total documents) scan | 1,000 scanned, 100 read |
| status count, SQL | O(1) result row | 1 row |
| status count, document | O(total documents) scan | 100 scanned |
| traveler report, shared | O(1) query | 1 query |
| traveler report, per service | O(1 + 3N) queries/calls | 61 queries for 20 |
| outbox append | O(1) row per event | 2 appends |
| outbox dispatch | O(pending) messages | 2 delivered |
| idempotent consume | O(1) key lookup per event | 2 processed |
| saga | O(S) steps, O(S) compensations worst case | 4 steps, 4 compensations |
| 2PC | O(P) prepares + O(P) commits, O(P) holds | 4 holds |
| projection queue, enqueue | O(E log E) per insert because the queue re-sorts | implementation detail |
| projection drain | O(E²) because `array_shift()` reindexes | implementation detail |
| async queue, submit | O(1) | 50 submits |
| async queue, drain | O(N²) because `array_shift()` reindexes | implementation detail |

The last three rows are deliberate limitations, not hidden optimizations. A
production implementation would use a heap or a ring buffer. The lab values a
readable mechanism over a fast one, and the benchmark numbers are interpreted
with that in mind.

## What the counters do not prove

- They do not prove that the wall-clock difference is caused by the counted
  operation. The benchmark tables show the combined cost of the configuration.
- They do not count bytes at the SQLite page level. `sqlBytesRead` is the size of
  the rows returned by the adapter, not the bytes read from disk.
- They do not model contention, locking waits or concurrent transactions.
- They do not include the cost of a real network, broker or payment provider.

The book may use these numbers to explain a mechanism. It must not turn a
counter into a latency claim, or a logical millisecond into a wall-clock one.
