#!/usr/bin/env bash
# One primary-failover experiment against native behind MySQL Router.
# Usage: failover.sh <run-name> <database> <count> <fail-after-seconds> [kill|stop|pause]
# Env: DW_MRC_STATE (scratch dir from up.sh; Python SDK under $DW_MRC_STATE/py),
# DW_NATIVE_BINARY, RATE (workflow starts per second, default 2).
set -uo pipefail
run=$1 db=$2 count=$3 fail_after=$4 mode=${5:-kill}
here=${DW_MRC_STATE:?set DW_MRC_STATE to a scratch directory}
scripts=$(cd "$(dirname "$0")" && pwd)
bin=${DW_NATIVE_BINARY:?set DW_NATIVE_BINARY to a native server binary built for glibc 2.39}
M=mirror.gcr.io/library/mysql@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7
PY=mirror.gcr.io/library/python@sha256:aa943ce411423c7582288f18707ff66eddcbaeb510502d747a72aeb34f30d159
UBUNTU=mirror.gcr.io/library/ubuntu@sha256:fe81560990fae86dc4743202b2cb4d3df98d0bcf949173cecef15ce1127ef852
case "$mode" in kill | stop | pause) ;; *) echo "mode must be kill, stop or pause" >&2; exit 2 ;; esac
[[ "$run" =~ ^[A-Za-z0-9_-]+$ && "$db" =~ ^[A-Za-z0-9_]+$ ]] || { echo "run and database must be plain names" >&2; exit 2; }

MYSQL_PWD=$(cat "$here/mysql-password")
DB_PASSWORD=$MYSQL_PWD
DW_AUTH_TOKEN=$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')
scheme=http
NATIVE_URL="$scheme://dw-mrc-native:8080"
export MYSQL_PWD DB_PASSWORD DW_AUTH_TOKEN NATIVE_URL
out=$here/$run
mkdir -p "$out"
sql() {
  local query=$1
  timeout 30 docker run --rm --network dw-mrc --memory 128m -e MYSQL_PWD "$M" mysql -h dw-mrc-router -P 6446 -uroot -N -e "$query" 2>/dev/null
}

sql "CREATE DATABASE IF NOT EXISTS $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON $db.* TO 'dw'@'%';"
timeout 30 docker rm -f dw-mrc-native >/dev/null 2>&1
timeout 60 docker run -d --name dw-mrc-native --hostname dw-mrc-native --network dw-mrc --memory 512m --user 1000:1000 \
  -v "$bin:/usr/local/bin/dw-native:ro" -e DW_RUST_EXPERIMENTAL=1 -e DW_RUST_MAINTENANCE_LOOP=1 -e DW_BIND_ADDRESS=0.0.0.0:8080 \
  -e DB_CONNECTION=mysql -e DB_HOST=dw-mrc-router -e DB_PORT=6446 -e "DB_DATABASE=$db" -e DB_USERNAME=dw -e DB_PASSWORD \
  -e DW_AUTH_TOKEN "$UBUNTU" /usr/local/bin/dw-native >/dev/null
timeout 60 bash -c "until docker run --rm --network dw-mrc --memory 64m -e NATIVE_URL $PY python -c 'import os,urllib.request;urllib.request.urlopen(os.environ[\"NATIVE_URL\"]+\"/api/ready\",timeout=2)' >/dev/null 2>&1; do sleep 1; done"

# Readiness/health probe every 0.5 s.
timeout 30 docker rm -f dw-mrc-probe >/dev/null 2>&1
timeout 60 docker run -d --name dw-mrc-probe --network dw-mrc --memory 64m -e NATIVE_URL "$PY" python -u -c '
import json, os, time, urllib.request
base = os.environ["NATIVE_URL"]
while True:
    row = {"t": round(time.time(), 2)}
    for p in ("ready", "health"):
        try:
            r = urllib.request.urlopen(base + "/api/" + p, timeout=2); row[p] = r.status
        except Exception as e:
            row[p] = getattr(e, "code", type(e).__name__)
    print(json.dumps(row), flush=True); time.sleep(0.5)' >/dev/null

# Load.
timeout 30 docker rm -f dw-mrc-load >/dev/null 2>&1
timeout 60 docker run -d --name dw-mrc-load --network dw-mrc --memory 512m --user 1000:1000 -e HOME=/tmp -e PYTHONPATH=/mrc/py \
  -e DW_AUTH_TOKEN -e NATIVE_URL -v "$here:/mrc" -v "$scripts:/scripts:ro" "$PY" python /scripts/load.py \
  --count "$count" --rate "${RATE:-2}" >/dev/null
sleep "$fail_after"
primary=$(sql "SELECT MEMBER_HOST FROM performance_schema.replication_group_members WHERE MEMBER_ROLE='PRIMARY'")
echo "{\"event\":\"kill\",\"mode\":\"$mode\",\"primary\":\"$primary\",\"t\":$(date +%s.%N | cut -c1-14)}" > "$out/kill.json"
case "$mode" in
  kill) timeout 30 docker kill "$primary" >/dev/null ;;
  stop) timeout 60 docker stop -t 20 "$primary" >/dev/null ;;
  pause) timeout 30 docker pause "$primary" >/dev/null; echo "$primary" > "$out/paused" ;;
  *) exit 2 ;;
esac
timeout 900 bash -c 'until [[ "$(docker inspect -f {{.State.Status}} dw-mrc-load 2>/dev/null)" == exited ]]; do sleep 5; done'
timeout 30 docker logs dw-mrc-load > "$out/load.jsonl" 2> "$out/load.err"
timeout 30 docker logs dw-mrc-probe > "$out/probe.jsonl" 2>/dev/null
timeout 30 docker logs dw-mrc-native > "$out/native.log" 2>&1
timeout 30 docker rm -f dw-mrc-probe dw-mrc-load >/dev/null
echo "$primary" > "$out/killed-primary"
