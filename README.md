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

The project is under construction; this README is updated as the chapters
land.

## 30-second demo

Requires Docker. Nothing is installed on the host.

```bash
make build       # build php:8.5-cli with the project extensions
make install     # composer install inside the container
make check       # PHPUnit + PHPStan level 8 + PHP-CS-Fixer
make scenario    # the one end-to-end trip-booking flow
```

The harness is discoverable:

```bash
make experiment                              # list experiments
make experiment ARGS="demo:smoke"
make benchmark                               # list benchmark suites
make benchmark ARGS="demo --repetitions=5"
```

Open a shell in the same container with `make shell`; stop it with `make down`.

## Stack

- PHP 8.5 CLI in Docker, strict types, no runtime framework.
- PDO SQLite as the concrete store; the document adapter is explicitly a
  document-shaped store on SQLite, not MongoDB.
- PHPUnit, PHPStan level 8 and PHP-CS-Fixer.
- Composer is for development tools only; there are no runtime dependencies.

## Documentation

- [docs/PHASES.md](docs/PHASES.md) — the build journal and the fixed plan.
- [docs/DECISIONS.md](docs/DECISIONS.md) — dated decisions and trade-offs.
- [docs/BENCHMARKS.md](docs/BENCHMARKS.md) — how numbers are taken and read.
- [docs/COMPLEXITY.md](docs/COMPLEXITY.md) — logical costs and measured behavior.
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — modules, runtime variants and data.

## License

MIT.
