# Matched development comparison on a dedicated host

[matched.json](matched.json) is the [v2 profile](../v2/README.md) with this
run's native source, optimized binary and machine facts filled in. The
workload, artifacts, limits and probe hashes are unchanged. The
[October 20 observations](observations-2026-10-20.md) are one PHP/native
pair on a disposable VM, run with `scripts/perf/run-server-port-matched.sh`
and summarized with `scripts/perf/summarize-server-port-matched.py`.

To repeat it on a fresh dedicated host with Docker and Compose:

```sh
# Exact source, optimized binary and locked SDK, as the v2 README describes.
export DW_PORT_SOURCE_ROOT=/abs/source DW_PORT_SDK_ROOT=/abs/sdk DW_PORT_NATIVE_BINARY=/abs/native/server
mkdir -p "$DW_PORT_SOURCE_ROOT/benchmarks/capacity/v1/bindings/php/vendor"
driver=scripts/perf/run-server-port-matched.sh
bash "$driver" PROFILE results/smoke-native native --smoke   # setup check only
bash "$driver" PROFILE results/php php
bash "$driver" PROFILE results/native native
docker run --rm -v "$PWD/results:/data" -v "$PWD/scripts/perf:/scripts:ro" python:3 \
  python -I /scripts/summarize-server-port-matched.py /data/php /data/native
```

`PROFILE` is a copy of `matched.json` with that run's `native_binary_sha256`;
the driver refuses a binary or probe script that does not match. Run the two
implementations sequentially on a host with no other workload, then delete the
host. The driver removes each Compose project and its volumes on exit.
