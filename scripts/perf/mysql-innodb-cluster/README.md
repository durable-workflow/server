# Native behind MySQL InnoDB Cluster and MySQL Router

A disposable harness that qualifies the native server on a highly available
MySQL topology: a three-member single-primary MySQL 8.4 InnoDB Cluster behind MySQL
Router, with native connected to Router's classic read-write port. Results and
the settings they support are in
[the November 1 checkpoint](../../../docs/rust-server-port-checkpoints/2026-11-01-mysql-innodb-cluster-router.md).

Everything runs in Docker on one host: three members at 1 GiB each, Router at
256 MiB, native at 512 MiB. Passwords are disposable fixture values.

```sh
export DW_MRC_STATE=$PWD/.mrc DW_NATIVE_BINARY=/abs/path/durable-workflow-server
mkdir -p "$DW_MRC_STATE"
docker run --rm --user 1000:1000 -e HOME=/tmp -v "$DW_MRC_STATE:/mrc" python:3.12-slim \
  pip install -q --target /mrc/py durable-workflow==2.12.2
bash up.sh                                    # cluster + Router on network dw-mrc
bash failover.sh run2 dwrun2 150 30 kill      # hard kill the primary 30 s in
bash dbcheck.sh dwrun2 > "$DW_MRC_STATE/run2/db.tsv"
python3 verify.py "$DW_MRC_STATE/run2" "$DW_MRC_STATE/run2/db.tsv"
RATE=1 bash failover.sh run4 dwrun4 90 30 pause   # hung primary
```

`failover.sh` starts native on a fresh database through Router, probes
`/api/ready` and `/api/health` every 0.5 s, drives Python SDK workflows (a
1 s timer then two activities), and fails the current primary. `kill` is a
crash, `stop` a clean shutdown, `pause` a hung host. Restart or unpause the
member afterwards; it rejoins on its own. `verify.py` reports outcomes,
readiness through the failover, and database integrity: one completion per
run, exact history counts, no duplicate history rows, no leftover leases.

Members get fixed addresses and static host entries, so a dead member's name
still resolves, as it does on real hosts. Docker's embedded DNS drops a killed
container's name, and the members then lost quorum entirely (see the
checkpoint).

Tear down: `docker rm -f -v dw-mrc-native dw-mrc-router dw-mrc-db1 dw-mrc-db2
dw-mrc-db3 && docker network rm dw-mrc`.
