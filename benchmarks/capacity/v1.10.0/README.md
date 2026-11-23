# Native child fan-out capacity suite 1.10.0

The `child-fan-out-1000` cell runs one parent with the default Server limit of
1,000 children in one flat parallel group. Each child returns its 1-based
index. The parent verifies all results in declaration order and returns
500,500. The synthetic 10-child control follows the same path and returns 55.

Each run uses a fresh internal Docker network and MySQL database. One SDK
process handles the parent and eight handle leaves for the full cell. The
control has two leaf processes. Every process handles at most one workflow at
a time. Run PHP, Python, and Rust separately. The [suite manifest](suite.json)
pins the baseline Server, database, and binding images. Use the same manifest,
host, and instrumentation when comparing Server arms.

## Run one binding

Prepare the SDK in its binding directory using the checked-in lock. For PHP,
run Composer install with the PHP image's runtime version. For Python, install
the hash-locked wheels for CPython 3.14 into `bindings/python/vendor` with
`--require-hashes --only-binary :all:`. Use the repository's
[patched pip bootstrap](../../../scripts/ci/bootstrap-patched-pip.sh) for
that install. For Rust, build in the pinned Rust image
using `cargo build --release --locked`. To use an existing locked dependency
directory or binary, set the respective `CAPACITY_PHP_VENDOR`,
`CAPACITY_PYTHON_SITE_PACKAGES`, or `CAPACITY_RUST_ADAPTER` to its local path.

Set `CAPACITY_MYSQLBINLOG` to a verified MySQL 8.4.13 binlog reader binary.
The runner mounts it into the pinned MySQL image and invokes it offline.
Pre-pull the pinned Docker images. From this directory, select the binding,
width, and a new output directory.

```sh
bash run.sh php 10 /tmp/child-fanout-php-control
bash run.sh php 1000 /tmp/child-fanout-php-baseline
```

To compare releases, supply an exact Server image digest as the fourth
argument. Run one workload at a time and use a separate output directory for
each binding and arm.

On versions with child-summary checkpoints, the runner applies the documented
staged DDL using the administrator of its disposable database. The scoped
application account then verifies the schema. Binary logging stays enabled.

## Evidence

- `identity.json` records the suite, exact images, host architecture, binding,
  width, and leaf process count. The SDK reports its installed version in
  `result.json` with the verified sum and elapsed client time.
- `history.tsv` independently proves 1,000 scheduled and completed children,
  one parent resume, one terminal parent event, the fan-in interval, and the
  last-child-to-parent-lease interval. The latter measures the durable parent
  task lease.
- `child-close-metrics.jsonl` contains one sample per committed child close,
  in binlog order. Compare early, middle, and late closes separately and report
  the final barrier wake on its own. Before-and-after status files record the
  database-wide InnoDB row-lock wait counter and total wait time. The
  [collector contract](collectors/mysql/README.md) describes the native shared
  transition lock and the coverage check.
- The long-history retention file shows how much of both Performance Schema
  buffers was used. The runner fails if a buffer fills or a close loses its
  statement evidence. Preserve the measured result only when all checks pass.

Keep the deterministic 1,000-child shape with the versioned regression corpus.
Use the control to check a new binding or profile before collecting the full
cell. These measurements supplement the
[standard workflow capacity suite](../v1/README.md).

[Server 3.21.0 baseline](results/server-3.21.0-linux-amd64/summary.json) keeps
the three pilot runs and their separate per-close CSV files. The traces
establish the workload and collector checks on the published baseline.

[Server 3.22.0 comparison](results/server-3.22.0-linux-amd64/summary.json)
uses the same workload and SDK pins. The staged bootstrap preserved MySQL's
binary logging. Each arm includes all 1,000 close samples and one parent
resume. Compare the final wake separately from intermediate closes.
