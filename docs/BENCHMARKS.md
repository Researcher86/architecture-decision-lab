# Benchmarks: How They Are Run and How to Read Them

The benchmark harness measures one thing well: wall-clock time of an operation
in this container, with the environment recorded next to it. It does not prove
an architecture is fast in production. It shows what this code did, on this
machine, at one commit.

## The rule

A number without its environment is an anecdote. Every report carries:

```text
Benchmark suite: 01-topology
PHP 8.5.11 on Linux 7.0.14-linuxkit, aarch64 x 12, memory_limit 512M, cgroup unlimited
OPcache off, JIT off, Xdebug off, allocator Zend MM
git 87bc2f0
```

The fields explain why the same code can produce a different number elsewhere:
OPcache and JIT change how the loop itself runs, the allocator changes what
`memory_get_usage()` means, and the cgroup decides how much page cache was
available.

## The two loops

- The **inner** loop runs the operation `iterations` times and is what gets
  timed. The count belongs to the benchmark: a point read needs thousands of
  iterations, a full booking needs hundreds.
- The **outer** loop repeats the whole measurement five times by default. One
  warm-up repetition is discarded.

`median` is the headline. `ops/sec` is derived from the median, not the mean.
`p95` uses nearest rank on five samples, so it is the maximum sample, not a
smooth percentile. `php delta` is PHP allocator usage, not RSS. Treat
differences below roughly 10% in this container as noise, not as a result.

## Commands

```bash
make benchmark                                      # list the suites
make benchmark ARGS="01-topology"
make benchmark ARGS="02-processing"
make benchmark ARGS="03-storage"
make benchmark ARGS="04-database"
make benchmark ARGS="05-outbox"
make benchmark ARGS="06-transaction"
make benchmark ARGS="07-consistency"
make benchmark ARGS="03-storage --repetitions=9"    # more samples
make benchmark ARGS="03-storage --format=json"      # keep the full report
```

Options are `--iterations`, `--repetitions`, `--warmups`, `--format` and
`--output`. The defaults come from the suite; an experiment command is never
used to produce a benchmark number.

## Environment and commit

These results were taken on 2026-10-27 in the project container:

```text
PHP 8.5.11 on Linux 7.0.14-linuxkit, aarch64 x 12, memory_limit 512M, cgroup unlimited
OPcache off, JIT off, Xdebug off, allocator Zend MM
git 87bc2f017532cd2576addc48b32cc5d3b158410f
```

`87bc2f0` is the benchmark commit. The release `v1.0.0` adds documentation and
tests on top of it; no runtime code changes between that commit and the tag.
The container runs on Docker Desktop for macOS, so the Linux kernel is
virtualized and the CPU is shared with the host. The book quotes these numbers
as one observed run, not as a hardware-independent constant.

## Chapter 01 — Deployment shape

Command: `make benchmark ARGS="01-topology"`. Three full bookings, 500
iterations each. Monolith and modular monolith share one database and use a
local transaction. Microservices use a database per service and a saga.

| benchmark | iterations | median | p95 | ops/sec | median per booking |
|---|---:|---:|---:|---:|---:|
| `topology.monolith_booking` | 500 | 645.366 ms | 668.035 ms | 775 | 1,291 µs |
| `topology.modular_booking` | 500 | 621.101 ms | 691.936 ms | 805 | 1,242 µs |
| `topology.microservices_booking` | 500 | 804.615 ms | 847.261 ms | 621 | 1,609 µs |

The microservice configuration is 24.6% slower per booking than the monolith
and 29.6% slower than the modular monolith in this run. That difference is not
"the network": the transport is in-process, and the remote latency is logical
time, not a sleep. The difference is the combination of five SQLite files, one
database per service, the saga coordinator and the extra queries needed to
commit each step separately. The topology experiment isolates the visible work
with counters: 122 remote calls and 3 compensations for 30 bookings, against
zero remote calls for the monolith.

The modular monolith is slightly faster than the monolith in this sample. Both
use the same database and the same transaction, so the difference is noise; do
not read a module boundary as a speed-up.

## Chapter 02 — Synchronous versus asynchronous processing

Command: `make benchmark ARGS="02-processing"`. 500 bookings per iteration.
`async_submit` only submits and leaves the work in the queue; `async_roundtrip`
submits and drains one job.

| benchmark | iterations | median | p95 | ops/sec | median per booking |
|---|---:|---:|---:|---:|---:|
| `processing.sync_booking` | 500 | 594.522 ms | 867.059 ms | 841 | 1,189 µs |
| `processing.async_submit` | 500 | 95.479 ms | 103.817 ms | 5,237 | 191 µs |
| `processing.async_roundtrip` | 500 | 666.326 ms | 723.606 ms | 750 | 1,333 µs |

A submit is 6.2 times cheaper than a full synchronous booking because it does
less: it creates the pending booking and enqueues a job. The queue grew to
2,500 jobs across the measured repetitions and the PHP allocator delta was
1.1 MiB. That is queue state, not a free lunch. When the worker drains the job,
the roundtrip is 12.1% slower than the synchronous path: the queue, the job
record and the second status update all cost something.

The experiment shows the other half of the trade-off: with a queue limit of 10,
only 10 of 50 submissions are accepted. The measured latency does not include
the rejection policy, because a queue with no bound has no rejection to
measure.

## Chapter 03 — SQL versus document storage

Command: `make benchmark ARGS="03-storage"`. 100 bookings and 10 travelers are
loaded once. The SQL lab uses normalized tables; the document lab stores each
booking aggregate as one JSON document in SQLite.

| benchmark | iterations | median | p95 | ops/sec | median per operation |
|---|---:|---:|---:|---:|---:|
| `storage.sql_point_read` | 5,000 | 88.984 ms | 90.210 ms | 56,190 | 17.8 µs |
| `storage.document_point_read` | 5,000 | 46.674 ms | 47.337 ms | 107,126 | 9.3 µs |
| `storage.sql_traveler_query` | 1,000 | 84.343 ms | 90.853 ms | 11,856 | 84.3 µs |
| `storage.document_traveler_query` | 1,000 | 452.050 ms | 461.615 ms | 2,212 | 452.1 µs |

A document point read is 1.9 times faster because it is one row and one JSON
decode; the SQL point read needs two statements (booking and lines) and returns
four rows. The same document shape is 5.4 times slower for the traveler query:
it scans and decodes every document. The SQL query uses the traveler index and
joins the lines.

This is a query-shape result, not a verdict on MongoDB. The document adapter has
no secondary indexes, no query planner statistics and no compression. A real
document database would change all three. The counter experiment makes the
shape explicit: for 100 bookings and 10 travelers, the SQL traveler query reads
300 rows and 83,356 bytes; the document store scans 1,000 documents and reads
524,830 bytes.

## Chapter 04 — Database per service

Command: `make benchmark ARGS="04-database"`. Thirty bookings for one traveler,
then the traveler report. The shared case is one SQL join; the per-service case
reads the booking list and calls three inventory services per booking.

| benchmark | iterations | median | p95 | ops/sec | median per report |
|---|---:|---:|---:|---:|---:|
| `database.shared_report` | 1,000 | 40.018 ms | 40.492 ms | 24,988 | 40.0 µs |
| `database.per_service_report` | 1,000 | 563.023 ms | 593.677 ms | 1,776 | 563.0 µs |

The per-service report is 14.1 times slower. This is the N+1 shape measured
directly: 1 query for the booking list plus 3 service calls per booking. For 20
bookings in the experiment, the report makes 61 SQL queries and transfers
3,840 request bytes. The shared report makes one query.

The per-service case also uses the microservice deployment shape and a saga,
while the shared case uses a modular monolith and a local transaction. The
report benchmark is not a pure database-ownership experiment; it is the report a
team actually ships after choosing the boundary. The database chapter's
experiment separates the booking outcome (19 confirmed, 1 cancelled, 4
compensations) from the report cost.

## Chapter 05 — Outbox versus dual write

Command: `make benchmark ARGS="05-outbox"`. Three hundred bookings per
iteration. `dual_write_booking` saves the booking and publishes immediately;
`outbox_booking` saves the booking and the outbox row; `booking_and_dispatch`
also dispatches and drains the projection.

| benchmark | iterations | median | p95 | ops/sec | median per booking |
|---|---:|---:|---:|---:|---:|
| `outbox.dual_write_booking` | 300 | 373.385 ms | 527.780 ms | 803 | 1,245 µs |
| `outbox.outbox_booking` | 300 | 345.188 ms | 481.659 ms | 869 | 1,151 µs |
| `outbox.booking_and_dispatch` | 300 | 485.556 ms | 634.100 ms | 618 | 1,619 µs |

The outbox booking is 7.6% *faster* than the dual write in this run. That is
not a claim that an outbox is free; it is the expected result when the dual
write does synchronous projection work inside the same measured call while the
outbox only appends a row. An earlier run had the opposite sign, which is why
the difference should be read as "about the same" rather than as a win. The
delivery cost is real and appears when the outbox is dispatched: the roundtrip
is 40.7% slower than the outbox write alone and 30.0% slower than the dual
write.

The experiment is the durable result: a dual write that crashes before the
publish loses one event out of one confirmed booking; the outbox retries and
loses none; a crash after the publish produces one duplicate, which the
idempotent projector absorbs.

## Chapter 06 — Saga versus two-phase commit

Command: `make benchmark ARGS="06-transaction"`. Three hundred bookings per
iteration. The local case uses one database; the saga and two-phase cases use a
database per service.

| benchmark | iterations | median | p95 | ops/sec | median per booking |
|---|---:|---:|---:|---:|---:|
| `transaction.local_booking` | 300 | 370.805 ms | 453.184 ms | 809 | 1,236 µs |
| `transaction.saga_booking` | 300 | 477.649 ms | 511.415 ms | 628 | 1,592 µs |
| `transaction.two_phase_booking` | 300 | 610.844 ms | 670.437 ms | 491 | 2,036 µs |

The saga is 28.8% slower than the local transaction, and two-phase commit is
64.7% slower than local and 27.9% slower than the saga. These are successful
bookings: the saga still pays for four independently committed steps, and 2PC
pays for four prepares and four commits. The failure cases are not faster.

The benchmark does not measure the blocking window. The transaction experiment
does: a crash after prepare leaves four holds, availability 9 instead of 10, and
the booking `pending` until an explicit recovery decision commits or aborts.
Recovery itself is not timed here.

## Chapter 07 — Strong versus eventual projection

Command: `make benchmark ARGS="07-consistency"`. Three hundred bookings with
dispatch and projection drain. The eventual case queues events for 50 ms of
logical time before the drain.

| benchmark | iterations | median | p95 | ops/sec | median per roundtrip |
|---|---:|---:|---:|---:|---:|
| `consistency.strong_roundtrip` | 300 | 551.688 ms | 555.924 ms | 544 | 1,839 µs |
| `consistency.eventual_roundtrip` | 300 | 527.824 ms | 744.556 ms | 568 | 1,759 µs |

The eventual roundtrip is 4.3% faster in the median, but its p95 is 41% above
its median and the sample has only five repetitions. The total work is the
same: both cases apply the event before the roundtrip ends; the eventual case
only moves the apply behind a queue. The real cost of eventual consistency is
not in this table. It is the window in which a read sees the old value: the
experiment performs 100 stale reads out of 100 before the drain and 0 out of
100 after it, while both cases converge to the same source availability.

## What is deliberately not measured

- Real network latency, packet loss, retries, timeouts or connection pools.
- Process crashes; the lab injects an exception at a named step.
- Concurrency: one process, one SQLite connection per database.
- Broker throughput, partition assignment or consumer lag under load.
- Recovery duration and coordinator failover.
- Production observability or memory beyond the PHP allocator delta.

These are not omissions to be patched with a bigger number. They are outside
the model. The book must not present a benchmark from this lab as evidence
about a real Kafka cluster, a real MongoDB deployment or a real payment
provider.

## Reading the numbers in the book

The book may quote a table from this document and the experiment output that
produced it. It must also quote the environment block and say that the numbers
come from one run in Docker on macOS. If a chapter needs a comparison that is
not in these tables, the comparison must be added to the lab and measured
again, not estimated from these numbers.
